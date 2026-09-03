/// TPIX TRADE — Wallet Provider
/// State management สำหรับ wallet connection, signing, verification
///
/// รองรับ 2 kinds:
///   - embedded: in-app wallet (BIP39/BIP32, private key เก็บใน SecureStorage)
///   - walletConnect: external wallet (MetaMask, Trust, Rainbow, ...) ผ่าน Reown AppKit
///
/// Developed by Xman Studio

import 'dart:async';
import 'dart:convert';

import 'package:bip39/bip39.dart' as bip39;
import 'package:bip32/bip32.dart' as bip32;
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:hex/hex.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:web3dart/web3dart.dart';
import '../models/api_models.dart';
import '../models/chain_config.dart';
import '../services/api_service.dart';
import '../services/external_wallet_service.dart';
import '../services/linked_wallet_signer.dart';
import '../services/wallet_session.dart';

/// ประเภทของ wallet ที่ user เลือกเชื่อม
enum WalletKind {
  /// in-app wallet — สร้าง/import mnemonic ในแอป private key ใน SecureStorage
  embedded,

  /// external wallet — เช่น MetaMask, Trust ผ่าน WalletConnect v2
  walletConnect,

  /// linked via cross-app deep link (เช่น TPIX Wallet ส่ง address มา)
  /// ลายเซ็น/transaction ต้องส่งกลับไปที่ wallet app ผ่าน deep link callback
  linked,
}

class WalletProvider extends ChangeNotifier {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  // Storage keys
  static const _keyPrivateKey = 'tpix_trade_pk';
  static const _keyMnemonic = 'tpix_trade_mnemonic';
  static const _keyWalletState = 'tpix_trade_wallet_state';
  static const _keyWalletKind = 'tpix_trade_wallet_kind';
  static const _keyExternalWalletName = 'tpix_trade_ext_wallet_name';
  static const _keySettings = 'tpix_trade_settings';

  // State
  String? _address;
  String? _mnemonic;
  bool _isConnecting = false;
  bool _isVerified = false;
  String? _error;
  int _activeChainId = 4289;
  String? _pendingMnemonic;
  WalletKind _kind = WalletKind.embedded;
  String? _externalWalletName; // 'MetaMask', 'Trust', etc.

  // Portfolio data
  List<TokenBalance> _balances = [];
  List<TradeOrder> _openOrders = [];
  List<TradeOrder> _tradeHistory = [];
  bool _isLoadingPortfolio = false;

  // User profile (sync จาก backend)
  UserProfile? _profile;
  bool _isLoadingProfile = false;

  // Settings
  String _language = 'en';
  String _currency = 'USD';
  String _defaultPair = 'BTC-USDT';
  String _defaultOrderType = 'limit';
  double _slippage = 0.5;
  bool _biometricEnabled = false;
  bool _pushNotifications = true;

  // Getters
  String? get address => _address;
  bool get isConnected => _address != null;
  bool get isConnecting => _isConnecting;
  bool get isVerified => _isVerified;
  String? get error => _error;
  int get activeChainId => _activeChainId;
  String? get pendingMnemonic => _pendingMnemonic;
  ChainConfig get activeChain => ChainConfig.byId(_activeChainId);

  /// ประเภทของ wallet ที่เชื่อมอยู่ — embedded หรือ walletConnect
  WalletKind get kind => _kind;

  /// true ถ้าใช้ external wallet (MetaMask, Trust, ...)
  bool get isExternalWallet => _kind == WalletKind.walletConnect;

  /// true ถ้าเชื่อมผ่าน deep link จากแอป wallet ภายนอก (TPIX Wallet ฯลฯ)
  bool get isLinkedWallet => _kind == WalletKind.linked;

  /// ชื่อ external wallet (ถ้าเชื่อมผ่าน WalletConnect)
  String? get externalWalletName => _externalWalletName;

  /// Mnemonic — เข้าถึงได้เฉพาะตอน backup pending เท่านั้น
  /// หลัง confirm backup แล้วจะ null ทันที (ไม่เก็บใน memory)
  /// External wallet จะคืน null เสมอ (ไม่มี mnemonic)
  String? get mnemonic =>
      _kind == WalletKind.embedded && _pendingMnemonic != null
          ? _mnemonic
          : null;

  String get shortAddress {
    if (_address == null) return '';
    return '${_address!.substring(0, 6)}...${_address!.substring(_address!.length - 4)}';
  }

  // Portfolio getters
  List<TokenBalance> get balances => _balances;
  List<TradeOrder> get openOrders => _openOrders;
  List<TradeOrder> get tradeHistory => _tradeHistory;
  bool get isLoadingPortfolio => _isLoadingPortfolio;

  // Profile getters
  UserProfile? get profile => _profile;
  bool get isLoadingProfile => _isLoadingProfile;
  String? get profileName => _profile?.name;
  String? get profileEmail => _profile?.email;
  String? get profileAvatar => _profile?.avatar;
  String? get referralCode => _profile?.referralCode;

  double get totalPortfolioValue =>
      _balances.fold(0.0, (sum, b) => sum + (b.usdValue ?? 0));

  // Settings getters
  String get language => _language;
  String get currency => _currency;
  String get defaultPair => _defaultPair;
  String get defaultOrderType => _defaultOrderType;
  double get slippage => _slippage;
  bool get biometricEnabled => _biometricEnabled;
  bool get pushNotifications => _pushNotifications;

  // ── Create New Wallet ──

  Future<void> createWallet() async {
    _isConnecting = true;
    _error = null;
    notifyListeners();

    try {
      // สร้าง mnemonic ด้วย BIP39 (ไม่ต้อง ethers.js!)
      final mnemonic = bip39.generateMnemonic(strength: 128); // 12 words
      final seed = bip39.mnemonicToSeed(mnemonic);
      final root = bip32.BIP32.fromSeed(seed);
      final child = root.derivePath("m/44'/4289'/0'/0/0");

      final privateKeyHex = HEX.encode(child.privateKey!);
      final credentials = EthPrivateKey.fromHex(privateKeyHex);
      final address = credentials.address.hex;

      // Store securely
      await _storage.write(key: _keyPrivateKey, value: privateKeyHex);
      await _storage.write(key: _keyMnemonic, value: mnemonic);

      _address = address;
      _mnemonic = mnemonic;
      _pendingMnemonic = mnemonic;
      _activeChainId = 4289;
      _isConnecting = false;
      notifyListeners();

      // Save state
      await _saveWalletState();

      // Register with backend (non-blocking)
      _registerWithBackend(address);
    } catch (e) {
      _error = 'Failed to create wallet';
      _isConnecting = false;
      notifyListeners();
      debugPrint('createWallet error: ${e.runtimeType}');
    }
  }

  // ── Import Wallet ──

  Future<bool> importWallet(String mnemonic) async {
    _isConnecting = true;
    _error = null;
    notifyListeners();

    try {
      final trimmed = mnemonic.trim().toLowerCase();

      if (!bip39.validateMnemonic(trimmed)) {
        _error = 'Invalid recovery phrase';
        _isConnecting = false;
        notifyListeners();
        return false;
      }

      final seed = bip39.mnemonicToSeed(trimmed);
      final root = bip32.BIP32.fromSeed(seed);
      final child = root.derivePath("m/44'/4289'/0'/0/0");

      final privateKeyHex = HEX.encode(child.privateKey!);
      final credentials = EthPrivateKey.fromHex(privateKeyHex);
      final address = credentials.address.hex;

      await _storage.write(key: _keyPrivateKey, value: privateKeyHex);
      await _storage.write(key: _keyMnemonic, value: trimmed);

      _address = address;
      _mnemonic = null; // C1: ไม่เก็บ mnemonic ใน memory — มีใน SecureStorage แล้ว
      _pendingMnemonic = null;
      _activeChainId = 4289;
      _isConnecting = false;
      notifyListeners();

      await _saveWalletState();
      _registerWithBackend(address);

      return true;
    } catch (e) {
      _error = 'Failed to import wallet';
      _isConnecting = false;
      notifyListeners();
      debugPrint('importWallet error: ${e.runtimeType}');
      return false;
    }
  }

  // ── Connect External Wallet (WalletConnect v2) ──

  /// เชื่อมกับ external wallet (MetaMask, Trust, Rainbow, ...) ผ่าน Reown AppKit
  /// ต้อง pass BuildContext มาด้วยเพราะ modal ผูกกับ widget tree
  /// คืน true ถ้าเชื่อมสำเร็จ false ถ้า user cancel หรือเกิด error
  Future<bool> connectExternalWallet(dynamic context) async {
    _isConnecting = true;
    _error = null;
    notifyListeners();

    try {
      final svc = ExternalWalletService();

      // Check configuration — ต้องตั้ง REOWN_PROJECT_ID มาก่อน
      if (!svc.isConfigured) {
        _error = 'External wallet ยังไม่ได้ตั้งค่า — ติดต่อผู้ดูแลระบบ';
        _isConnecting = false;
        notifyListeners();
        return false;
      }

      // Initialize AppKit (lazy, ครั้งเดียวต่อ app lifecycle)
      await svc.init(context);

      // Open modal — user เลือก wallet + อนุมัติ
      final session = await svc.connect();
      if (session == null) {
        // User cancel หรือ wallet reject
        _isConnecting = false;
        notifyListeners();
        return false;
      }

      // เก็บ session info
      _kind = WalletKind.walletConnect;
      _address = session.address;
      _externalWalletName = session.walletName;
      _activeChainId = session.chainId;
      _mnemonic = null;
      _pendingMnemonic = null;
      _isConnecting = false;
      notifyListeners();

      await _saveWalletState();

      // Register with backend (non-blocking) — backend จะออก auth token
      _registerWithBackend(session.address);

      return true;
    } catch (e) {
      _error = 'Failed to connect external wallet';
      _isConnecting = false;
      notifyListeners();
      debugPrint('connectExternalWallet error: ${e.runtimeType}');
      return false;
    }
  }

  // ── Link From Deep Link (cross-app from TPIX Wallet, etc.) ──

  /// เชื่อม wallet ผ่าน deep link จากแอป wallet ภายนอก (เช่น TPIX Wallet)
  /// — ไม่ต้องเปิด wallet picker, ใช้ address ที่ wallet app ส่งมาเลย
  ///
  /// Address มาจาก deep link `tpixtrade://connect?address=0x..&chain=4289`
  /// คืน true ถ้า link สำเร็จ, false ถ้า address ไม่ valid หรือ chain ไม่รองรับ
  Future<bool> linkFromDeepLink({
    required String address,
    required int chainId,
    String? walletName,
    String? nonce,
    String? signature,
  }) async {
    // Defense in depth — validate address อีกครั้ง
    if (!RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(address)) {
      _error = 'Invalid wallet address';
      notifyListeners();
      return false;
    }

    try {
      _kind = WalletKind.linked;
      _address = address;
      _externalWalletName = walletName ?? 'TPIX Wallet';
      _activeChainId = chainId;
      _mnemonic = null;
      _pendingMnemonic = null;
      _isVerified = false; // ต้อง verify ก่อนเทรด — แต่ browse balance/markets ได้
      _isConnecting = false;
      _error = null;
      notifyListeners();

      await _saveWalletState();

      /*
       * TPIX Wallet รุ่นใหม่เซ็นข้อความยืนยันมาให้พร้อมกับการเชื่อมเลย (nonce+signature)
       * → ยืนยันกับเซิร์ฟเวอร์ได้ทันทีในฮอปเดียว ไม่ต้องเด้งกลับไปขอลายเซ็นอีกรอบ
       * กระเป๋ารุ่นเก่าไม่ส่งมา → เดินเส้นทางเดิม (ขอลายเซ็นผ่าน deep link)
       */
      final signed = nonce != null &&
          nonce.isNotEmpty &&
          signature != null &&
          RegExp(r'^0x[a-fA-F0-9]{130}$').hasMatch(signature);

      // Register address กับ backend (best effort) — ได้ user record
      _registerWithBackend(address, verify: !signed);

      if (signed) {
        unawaited(
          _submitVerification(signature: signature, nonce: nonce)
              .catchError((_) => false),
        );
      }

      // โหลด portfolio (read-only)
      loadPortfolio();

      return true;
    } catch (e) {
      _error = 'Failed to link wallet';
      notifyListeners();
      debugPrint('linkFromDeepLink error: ${e.runtimeType}');
      return false;
    }
  }

  // ── Sign Message ──

  Future<String?> signMessage(String message) async {
    try {
      // Dispatch ตาม wallet kind
      if (_kind == WalletKind.walletConnect) {
        // External wallet — ขอลายเซ็นผ่าน WalletConnect
        if (_address == null) return null;
        return await ExternalWalletService().signPersonalMessage(
          address: _address!,
          message: message,
        );
      }

      if (_kind == WalletKind.linked) {
        // Linked wallet — ไม่มี private key ในแอป
        // ส่ง deep-link ไป TPIX Wallet ขอลายเซ็น แล้วรอ callback กลับมา
        // (LinkedWalletSigner เปิดแอพ wallet → user confirm → callback → resolve)
        return await LinkedWalletSigner().requestSignature(message);
      }

      // Embedded wallet — sign locally ด้วย private key
      final privateKey = await _storage.read(key: _keyPrivateKey);
      if (privateKey == null) return null;

      final credentials = EthPrivateKey.fromHex(privateKey);
      final messageBytes = Uint8List.fromList(message.codeUnits);
      final signature = credentials.signPersonalMessageToUint8List(messageBytes);
      return '0x${HEX.encode(signature)}';
    } catch (e) {
      debugPrint('signMessage error: ${e.runtimeType}');
      return null;
    }
  }

  // ── Send Transaction (BSC — สำหรับเทรดจริงผ่าน PancakeSwap) ──

  /// ส่งธุรกรรมบนเชน BSC (56) — dispatch ตามชนิด wallet:
  ///  embedded      → เซ็นด้วย private key ในเครื่อง ส่งผ่าน BSC RPC ตรง
  ///  walletConnect → ขอ external wallet ส่งผ่าน Reown (สลับเชนให้ก่อน)
  ///  linked        → deep-link ไป TPIX Wallet ให้ยืนยัน+เซ็น+broadcast
  ///                  แล้วส่ง tx hash กลับ (cross-app sign-tx protocol)
  /// คืน tx hash หรือ null (พร้อมตั้ง [error] อธิบายเหตุ)
  Future<String?> sendBscTransaction({
    required String to,
    Uint8List? data,
    BigInt? value,
    String? summary,
  }) async {
    if (_address == null) {
      _error = 'Wallet not connected';
      notifyListeners();
      return null;
    }

    if (_kind == WalletKind.linked) {
      return _sendViaLinkedWallet(
          to: to, data: data, value: value, summary: summary);
    }

    if (_kind == WalletKind.walletConnect) {
      return _sendViaWalletConnect(to: to, data: data, value: value);
    }

    return _sendViaEmbedded(to: to, data: data, value: value);
  }

  /// Linked wallet (TPIX Wallet) — ส่งคำขอธุรกรรมข้ามแอพ ผู้ใช้ยืนยันใน
  /// TPIX Wallet ซึ่งเป็นผู้ถือ key แล้ว broadcast เอง คืน hash ผ่าน callback
  Future<String?> _sendViaLinkedWallet({
    required String to,
    Uint8List? data,
    BigInt? value,
    String? summary,
  }) async {
    final hash = await LinkedWalletSigner().requestTransaction(
      to: to,
      valueHex: value != null ? '0x${value.toRadixString(16)}' : null,
      dataHex: data != null ? '0x${HEX.encode(data)}' : null,
      chainId: 56,
      summary: summary,
    );
    if (hash == null) {
      _error = 'ธุรกรรมถูกปฏิเสธใน TPIX Wallet หรือหมดเวลา';
      notifyListeners();
    }
    return hash;
  }

  /// External wallet — เชนของ wallet ต้องเป็น BSC ก่อนส่ง (ขอสลับให้อัตโนมัติ)
  Future<String?> _sendViaWalletConnect({
    required String to,
    Uint8List? data,
    BigInt? value,
  }) async {
    final svc = ExternalWalletService();

    if (svc.selectedChainId != 56) {
      await svc.switchChain(56);
      if (svc.selectedChainId != 56) {
        _error = 'กรุณาสลับ wallet ไปเชน BSC เพื่อเทรด';
        notifyListeners();
        return null;
      }
      _activeChainId = 56;
      notifyListeners();
    }

    final hash = await svc.sendTransaction(
      from: _address!,
      to: to,
      valueHex: value != null ? '0x${value.toRadixString(16)}' : null,
      dataHex: data != null ? '0x${HEX.encode(data)}' : null,
    );
    if (hash == null) {
      _error = 'ธุรกรรมถูกปฏิเสธจาก wallet';
      notifyListeners();
    }
    return hash;
  }

  /// Embedded wallet — เซ็นเองในเครื่อง private key ไม่ออกจาก SecureStorage
  /// gas: estimate จากเชนจริง + buffer 30% (swap บน Pancake ~200k ประมาณเองไม่ได้)
  Future<String?> _sendViaEmbedded({
    required String to,
    Uint8List? data,
    BigInt? value,
  }) async {
    final privateKey = await _storage.read(key: _keyPrivateKey);
    if (privateKey == null) {
      _error = 'Wallet key not found';
      notifyListeners();
      return null;
    }

    final credentials = EthPrivateKey.fromHex(privateKey);
    final toAddress = EthereumAddress.fromHex(to);
    final etherValue =
        value != null ? EtherAmount.inWei(value) : null;

    final rpcs = [ChainConfig.bsc.rpcUrl, ...ChainConfig.bsc.fallbackRpcs];
    for (final rpc in rpcs) {
      final client = Web3Client(rpc, http.Client());
      try {
        // Estimate ก่อนส่ง — ถ้า revert ตั้งแต่ estimate = tx จะ fail แน่ อย่าส่ง
        BigInt gasEstimate;
        try {
          gasEstimate = await client.estimateGas(
            sender: credentials.address,
            to: toAddress,
            data: data,
            value: etherValue,
          );
        } catch (_) {
          _error = 'ธุรกรรมจะล้มเหลวบนเชน — เช็คยอดคงเหลือ/ค่า gas (BNB)';
          notifyListeners();
          return null;
        }

        final gasPrice = await client.getGasPrice();
        final maxGas =
            (gasEstimate * BigInt.from(13) ~/ BigInt.from(10)).toInt();

        final hash = await client.sendTransaction(
          credentials,
          Transaction(
            to: toAddress,
            data: data,
            value: etherValue,
            gasPrice: gasPrice,
            maxGas: maxGas,
          ),
          chainId: 56,
        );
        return hash;
      } catch (e) {
        debugPrint('sendBscTransaction rpc failed: ${e.runtimeType}');
        // RPC ตัวนี้ล่ม — ลองตัวถัดไป
      } finally {
        client.dispose();
      }
    }

    _error = 'ส่งธุรกรรมไม่สำเร็จ — เครือข่าย BSC ขัดข้อง ลองใหม่';
    notifyListeners();
    return null;
  }

  // ── Verify with Backend ──

  Future<bool> verifyWithBackend() async {
    if (_address == null) return false;

    try {
      final signData = await ApiService().walletRequestSignature(_address!);
      if (signData == null) return false;

      final message = signData['message'] as String?;
      final nonce = signData['nonce'] as String?;
      if (message == null || nonce == null) return false;

      // Linked wallet: จดคำขอไว้บนดิสก์ด้วย — ถ้าแอปถูกเปิดใหม่ระหว่างไปเซ็นใน
      // TPIX Wallet, DeepLinkService จะเรียก completeVerificationFromCallback ให้จบเอง
      final signature = _kind == WalletKind.linked
          ? await LinkedWalletSigner().requestSignature(
              message,
              tag: 'verify',
              meta: {'nonce': nonce, 'address': _address},
            )
          : await signMessage(message);
      if (signature == null) return false;

      return _submitVerification(signature: signature, nonce: nonce);
    } catch (e) {
      debugPrint('verifyWithBackend error: ${e.runtimeType}');
      _isVerified = false;
      notifyListeners();
      return false;
    }
  }

  /// ทำการยืนยันต่อจาก callback ของกระเป๋า เมื่อแอปถูกเปิดใหม่ระหว่างรอลายเซ็น.
  ///
  /// [meta] คือสิ่งที่ verifyWithBackend จดไว้: nonce ฝั่งเซิร์ฟเวอร์ + address
  /// ต้องเป็นกระเป๋าเดียวกับที่โหลดกลับมาจากดิสก์ ไม่งั้นไม่รับ (กันลายเซ็นข้ามกระเป๋า)
  Future<bool> completeVerificationFromCallback({
    required String signature,
    Map<String, dynamic>? meta,
  }) async {
    final nonce = meta?['nonce']?.toString();
    final address = meta?['address']?.toString();
    if (nonce == null || nonce.isEmpty || address == null) return false;
    if (_address == null || _address!.toLowerCase() != address.toLowerCase()) {
      return false;
    }

    try {
      return await _submitVerification(signature: signature, nonce: nonce);
    } catch (e) {
      debugPrint('completeVerificationFromCallback error: ${e.runtimeType}');
      return false;
    }
  }

  Future<bool> _submitVerification({
    required String signature,
    required String nonce,
  }) async {
    final verifyData = await ApiService().walletVerifySignature(
      walletAddress: _address!,
      signature: signature,
      nonce: nonce,
    );

    _isVerified = verifyData?['verified'] == true;
    notifyListeners();

    // Auto-fetch profile after successful verification (non-blocking)
    // เพื่อ sync settings/preferences จาก backend (cross-device)
    if (_isVerified) {
      loadProfile().catchError((_) => false);
    }

    return _isVerified;
  }

  // ── Profile Sync (cross-device) ──

  /// ดึง profile จาก backend แล้ว merge preferences เข้า local settings
  /// (backend = source of truth สำหรับ settings cross-device)
  Future<bool> loadProfile() async {
    if (_address == null || !_isVerified) return false;

    _isLoadingProfile = true;
    notifyListeners();

    try {
      final p = await ApiService().getProfile(_address!);
      if (p != null) {
        _profile = p;
        // Merge backend preferences → local settings (backend wins)
        _applyPreferencesToSettings(p.preferences);
        await _saveSettings(); // persist locally too
      }
      _isLoadingProfile = false;
      notifyListeners();
      return p != null;
    } catch (e) {
      debugPrint('loadProfile error: ${e.runtimeType}');
      _isLoadingProfile = false;
      notifyListeners();
      return false;
    }
  }

  /// อัปเดต profile (name/email/avatar) ไป backend แล้ว update local
  /// คืน true ถ้าสำเร็จ — ถ้าเกิด error เก็บไว้ใน _error
  Future<bool> updateProfile({
    String? name,
    String? email,
    String? avatar,
  }) async {
    if (_address == null || !_isVerified) {
      _error = 'ต้องยืนยัน wallet ก่อน';
      notifyListeners();
      return false;
    }

    try {
      final updated = await ApiService().updateProfile(
        walletAddress: _address!,
        name: name,
        email: email,
        avatar: avatar,
      );
      if (updated == null) {
        _error = 'ไม่สามารถบันทึก profile ได้';
        notifyListeners();
        return false;
      }
      _profile = updated;
      notifyListeners();
      return true;
    } catch (e) {
      _error = 'อัปเดต profile ล้มเหลว';
      notifyListeners();
      debugPrint('updateProfile error: ${e.runtimeType}');
      return false;
    }
  }

  /// Apply preferences map → local settings fields
  void _applyPreferencesToSettings(Map<String, dynamic> prefs) {
    if (prefs.isEmpty) return;
    final lang = prefs['language'] as String?;
    if (lang != null && (lang == 'en' || lang == 'th')) _language = lang;
    final cur = prefs['currency'] as String?;
    if (cur != null) _currency = cur;
    final pair = prefs['default_pair'] as String?;
    if (pair != null) _defaultPair = pair;
    final orderType = prefs['default_order_type'] as String?;
    if (orderType != null) _defaultOrderType = orderType;
    final slip = prefs['slippage'];
    if (slip is num) _slippage = slip.toDouble();
    final chainId = prefs['default_chain_id'];
    if (chainId is num) _activeChainId = chainId.toInt();
    final notifs = prefs['notifications'];
    if (notifs is Map) {
      final push = notifs['push'];
      if (push is bool) _pushNotifications = push;
    }
    final bio = prefs['biometric_enabled'];
    if (bio is bool) _biometricEnabled = bio;
  }

  /// Build preferences map จาก local settings — สำหรับส่งไป backend
  Map<String, dynamic> _settingsToPreferences() => {
        'language': _language,
        'currency': _currency,
        'default_pair': _defaultPair,
        'default_order_type': _defaultOrderType,
        'slippage': _slippage,
        'default_chain_id': _activeChainId,
        'biometric_enabled': _biometricEnabled,
        'notifications': {'push': _pushNotifications},
      };

  // ── Load Saved Wallet ──

  Future<void> loadSavedWallet() async {
    try {
      // โทเคนเซสชัน 30 วัน — ต้องมีก่อนตัดสินใจว่าจะขอลายเซ็นใหม่ไหม
      await WalletSession.load();

      final prefs = await SharedPreferences.getInstance();
      final savedAddress = prefs.getString(_keyWalletState);
      final savedKindStr = prefs.getString(_keyWalletKind);

      if (savedAddress == null) {
        // ไม่มี wallet เก่า — แค่ load settings
        await _loadSettings();
        return;
      }

      // Determine wallet kind (default: embedded — backward compat กับเวอร์ชันก่อน)
      final savedKind = switch (savedKindStr) {
        'walletConnect' => WalletKind.walletConnect,
        'linked' => WalletKind.linked,
        _ => WalletKind.embedded,
      };

      if (savedKind == WalletKind.linked) {
        // Linked from cross-app deep link — restore as-is (ไม่มี session ของเราเอง)
        _kind = WalletKind.linked;
        _address = savedAddress;
        _externalWalletName =
            prefs.getString(_keyExternalWalletName) ?? 'TPIX Wallet';
        _activeChainId = prefs.getInt('tpix_trade_chain_id') ?? 4289;
        notifyListeners();

        /*
         * เชื่อมค้างไว้ได้เลย — ถ้าเคยเซ็นผ่านแล้วมีโทเคน 30 วันของกระเป๋านี้
         * ถือว่ายืนยันแล้วทันที (ตรวจกับเซิร์ฟเวอร์เบื้องหลัง ล้างเฉพาะเมื่อโดน 403)
         * ไม่มีโทเคน = รอผู้ใช้กดยืนยันเอง ไม่เด้งไปกระเป๋าตั้งแต่เปิดแอป
         */
        if (WalletSession.isFor(_address)) {
          _isVerified = true;
          notifyListeners();
          unawaited(_probeSession());
        }
      } else if (savedKind == WalletKind.walletConnect) {
        // External wallet — Reown AppKit เก็บ session เองใน storage
        // เราต้อง resume session แล้วเช็คว่า address ตรงกับที่บันทึกไว้
        final session = ExternalWalletService().resumeSession();
        if (session != null &&
            session.address.toLowerCase() == savedAddress.toLowerCase()) {
          _kind = WalletKind.walletConnect;
          _address = session.address;
          _externalWalletName =
              session.walletName ?? prefs.getString(_keyExternalWalletName);
          _activeChainId = session.chainId;
          notifyListeners();

          // Auto re-verify กับ backend
          _registerWithBackend(_address!);
        } else {
          // Session หมดอายุ/หาย — clear stored state
          await prefs.remove(_keyWalletState);
          await prefs.remove(_keyWalletKind);
          await prefs.remove(_keyExternalWalletName);
        }
      } else {
        // Embedded wallet — derive address จาก private key
        final privateKey = await _storage.read(key: _keyPrivateKey);
        if (privateKey != null) {
          // S4: Derive address จาก private key จริง ไม่เชื่อค่าใน SharedPreferences
          final credentials = EthPrivateKey.fromHex(privateKey);
          _address = credentials.address.hex;
          _kind = WalletKind.embedded;
          _activeChainId = prefs.getInt('tpix_trade_chain_id') ?? 4289;

          // อัปเดต stored address ถ้าไม่ตรง (ป้องกัน tampering)
          if (_address != savedAddress) {
            await prefs.setString(_keyWalletState, _address!);
          }
          notifyListeners();

          // Auto re-verify กับ backend — เพื่อให้ได้ auth token สำหรับเทรด
          // ไม่ block UI (fire-and-forget)
          _registerWithBackend(_address!);
        } else {
          await prefs.remove(_keyWalletState);
        }
      }

      // Load settings
      await _loadSettings();
    } catch (e) {
      debugPrint('loadSavedWallet error: ${e.runtimeType}');
    }
  }

  // ── Confirm Mnemonic Backup ──

  void confirmMnemonicBackup() {
    _pendingMnemonic = null;
    _mnemonic = null; // ลบ mnemonic จาก memory ทันทีหลัง backup
    notifyListeners();
  }

  // ── Disconnect ──

  Future<void> disconnect() async {
    // S6: Await secure storage deletion — ต้อง clear key ก่อน update state
    try {
      // External wallet — ยกเลิก WC session ก่อน
      if (_kind == WalletKind.walletConnect) {
        await ExternalWalletService().disconnect();
      }

      // Embedded wallet — clear secure storage (ทำเสมอ เผื่อมีเศษค้าง)
      await _storage.delete(key: _keyPrivateKey);
      await _storage.delete(key: _keyMnemonic);

      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_keyWalletState);
      await prefs.remove(_keyWalletKind);
      await prefs.remove(_keyExternalWalletName);
    } catch (_) {
      // Best effort — continue with state cleanup
    }

    ApiService().clearToken();
    await WalletSession.clear();

    _address = null;
    _mnemonic = null;
    _isVerified = false;
    _error = null;
    _pendingMnemonic = null;
    _kind = WalletKind.embedded;
    _externalWalletName = null;
    _balances = [];
    _openOrders = [];
    _tradeHistory = [];
    _profile = null;
    notifyListeners();
  }

  // ── Portfolio Data ──

  Future<void> loadPortfolio() async {
    if (_address == null) return;
    _isLoadingPortfolio = true;
    notifyListeners();

    try {
      final results = await Future.wait([
        ApiService().getWalletBalances(_address!, chainId: _activeChainId),
        ApiService().getOpenOrders(_address!),
        ApiService().getTradeHistory(_address!),
      ]);
      _balances = results[0] as List<TokenBalance>;
      _openOrders = results[1] as List<TradeOrder>;
      _tradeHistory = results[2] as List<TradeOrder>;
    } catch (_) {
      // ใช้ค่าเดิม — ไม่ clear
    }

    _isLoadingPortfolio = false;
    notifyListeners();
  }

  // ── Chain Management ──

  void switchChain(int chainId) {
    _activeChainId = chainId;
    notifyListeners();
    SharedPreferences.getInstance().then((prefs) {
      prefs.setInt('tpix_trade_chain_id', chainId);
    }).catchError((_) {});

    // External wallet — ขอให้ wallet สลับเชนด้วย (best effort)
    // บาง wallet จะเปิด popup ให้ user confirm
    if (_kind == WalletKind.walletConnect) {
      ExternalWalletService().switchChain(chainId).catchError((_) => false);
    }

    // รีโหลด portfolio data ตาม chain ใหม่
    loadPortfolio();
  }

  // ── Settings ──

  Future<void> updateSettings({
    String? language,
    String? currency,
    String? defaultPair,
    String? defaultOrderType,
    double? slippage,
    bool? biometricEnabled,
    bool? pushNotifications,
  }) async {
    if (language != null) _language = language;
    if (currency != null) _currency = currency;
    if (defaultPair != null) _defaultPair = defaultPair;
    if (defaultOrderType != null) _defaultOrderType = defaultOrderType;
    if (slippage != null) _slippage = slippage;
    if (biometricEnabled != null) _biometricEnabled = biometricEnabled;
    if (pushNotifications != null) _pushNotifications = pushNotifications;
    notifyListeners();
    await _saveSettings();

    // Sync ไป backend (non-blocking, best-effort) — เฉพาะถ้า verified
    // biometric_enabled เป็น device-local เท่านั้น (ไม่ส่ง)
    if (_isVerified && _address != null) {
      final prefsToSync = _settingsToPreferences()
        ..remove('biometric_enabled');
      ApiService()
          .updateProfile(walletAddress: _address!, preferences: prefsToSync)
          .then((updated) {
        if (updated != null) {
          _profile = updated;
          notifyListeners();
        }
      }).catchError((_) {});
    }
  }

  // ── Error ──

  void clearError() {
    _error = null;
    notifyListeners();
  }

  // ── Private Helpers ──

  Future<void> _saveWalletState() async {
    final prefs = await SharedPreferences.getInstance();
    if (_address != null) {
      await prefs.setString(_keyWalletState, _address!);
      await prefs.setInt('tpix_trade_chain_id', _activeChainId);
      await prefs.setString(
        _keyWalletKind,
        switch (_kind) {
          WalletKind.walletConnect => 'walletConnect',
          WalletKind.linked => 'linked',
          WalletKind.embedded => 'embedded',
        },
      );
      if (_externalWalletName != null) {
        await prefs.setString(_keyExternalWalletName, _externalWalletName!);
      } else {
        await prefs.remove(_keyExternalWalletName);
      }
    }
  }

  void _registerWithBackend(String address, {bool verify = true}) {
    ApiService()
        .walletConnect(
          walletAddress: address,
          chainId: _activeChainId,
        )
        .then((_) async {
          if (!verify) return false;

          // มีเซสชัน 30 วันของกระเป๋านี้อยู่แล้ว → ไม่ต้องขอลายเซ็นใหม่ทุกครั้งที่เปิดแอป
          if (WalletSession.isFor(address)) {
            _isVerified = true;
            notifyListeners();
            return _probeSession();
          }

          return verifyWithBackend();
        })
        .catchError((_) => false);
  }

  /// เซสชันที่เก็บไว้ยังใช้ได้ไหม — ล้างเฉพาะเมื่อเซิร์ฟเวอร์ปฏิเสธชัดๆ (403)
  /// ต่อเน็ตไม่ได้ไม่ถือว่าหมดอายุ ไม่งั้นออฟไลน์แป๊บเดียวก็ต้องไปเซ็นใหม่
  Future<bool> _probeSession() async {
    final address = _address;
    if (address == null) return false;

    final status = await ApiService().probeWalletSession(address);
    if (status == 403) {
      await WalletSession.clear();
      _isVerified = false;
      notifyListeners();
      return false;
    }

    if (status == 200 && _profile == null) {
      loadProfile().catchError((_) => false);
    }

    return true;
  }

  Future<void> _saveSettings() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keySettings, _settingsToJson());
  }

  Future<void> _loadSettings() async {
    final prefs = await SharedPreferences.getInstance();
    final json = prefs.getString(_keySettings);
    if (json != null) _settingsFromJson(json);
  }

  String _settingsToJson() {
    return jsonEncode({
      'language': _language,
      'currency': _currency,
      'defaultPair': _defaultPair,
      'defaultOrderType': _defaultOrderType,
      'slippage': _slippage,
      'biometricEnabled': _biometricEnabled,
      'pushNotifications': _pushNotifications,
    });
  }

  void _settingsFromJson(String raw) {
    try {
      final map = jsonDecode(raw) as Map<String, dynamic>;
      _language = (map['language'] as String?) ?? _language;
      _currency = (map['currency'] as String?) ?? _currency;
      _defaultPair = (map['defaultPair'] as String?) ?? _defaultPair;
      _defaultOrderType = (map['defaultOrderType'] as String?) ?? _defaultOrderType;
      _slippage = (map['slippage'] as num?)?.toDouble() ?? _slippage;
      _biometricEnabled = (map['biometricEnabled'] as bool?) ?? _biometricEnabled;
      _pushNotifications = (map['pushNotifications'] as bool?) ?? _pushNotifications;
    } catch (_) {
      // Use defaults — ค่าเก่า format ผิดก็ไม่เป็นไร
    }
  }
}
