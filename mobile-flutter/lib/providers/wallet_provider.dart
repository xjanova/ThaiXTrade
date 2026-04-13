/// TPIX TRADE — Wallet Provider
/// State management สำหรับ wallet connection, signing, verification
/// ใช้ BIP39/BIP32 เหมือน TPIX Wallet — ไม่พึ่ง ethers.js อีก
///
/// Developed by Xman Studio

import 'package:bip39/bip39.dart' as bip39;
import 'package:bip32/bip32.dart' as bip32;
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:hex/hex.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:web3dart/web3dart.dart';
import '../models/chain_config.dart';
import '../services/api_service.dart';

class WalletProvider extends ChangeNotifier {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  // Storage keys
  static const _keyPrivateKey = 'tpix_trade_pk';
  static const _keyMnemonic = 'tpix_trade_mnemonic';
  static const _keyWalletState = 'tpix_trade_wallet_state';
  static const _keySettings = 'tpix_trade_settings';

  // State
  String? _address;
  String? _mnemonic;
  bool _isConnecting = false;
  bool _isVerified = false;
  String? _error;
  int _activeChainId = 4289;
  String? _pendingMnemonic;

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

  /// Mnemonic ของ wallet ปัจจุบัน (ใช้ตอน backup/export)
  String? get mnemonic => _mnemonic;

  String get shortAddress {
    if (_address == null) return '';
    return '${_address!.substring(0, 6)}...${_address!.substring(_address!.length - 4)}';
  }

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
      debugPrint('createWallet error: $e');
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
      _mnemonic = trimmed;
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
      debugPrint('importWallet error: $e');
      return false;
    }
  }

  // ── Sign Message ──

  Future<String?> signMessage(String message) async {
    try {
      final privateKey = await _storage.read(key: _keyPrivateKey);
      if (privateKey == null) return null;

      final credentials = EthPrivateKey.fromHex(privateKey);
      final messageBytes = Uint8List.fromList(message.codeUnits);
      final signature = credentials.signPersonalMessageToUint8List(messageBytes);
      return '0x${HEX.encode(signature)}';
    } catch (e) {
      debugPrint('signMessage error: $e');
      return null;
    }
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

      final signature = await signMessage(message);
      if (signature == null) return false;

      final verifyData = await ApiService().walletVerifySignature(
        walletAddress: _address!,
        signature: signature,
        nonce: nonce,
      );

      _isVerified = verifyData?['verified'] == true;
      notifyListeners();
      return _isVerified;
    } catch (e) {
      debugPrint('verifyWithBackend error: $e');
      _isVerified = false;
      notifyListeners();
      return false;
    }
  }

  // ── Load Saved Wallet ──

  Future<void> loadSavedWallet() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final savedAddress = prefs.getString(_keyWalletState);

      if (savedAddress != null) {
        final hasKey = await _storage.read(key: _keyPrivateKey);
        if (hasKey != null) {
          _address = savedAddress;
          _activeChainId = prefs.getInt('tpix_trade_chain_id') ?? 4289;
          notifyListeners();
        } else {
          await prefs.remove(_keyWalletState);
        }
      }

      // Load settings
      await _loadSettings();
    } catch (e) {
      debugPrint('loadSavedWallet error: $e');
    }
  }

  // ── Confirm Mnemonic Backup ──

  void confirmMnemonicBackup() {
    _pendingMnemonic = null;
    notifyListeners();
  }

  // ── Disconnect ──

  void disconnect() {
    _storage.delete(key: _keyPrivateKey).catchError((_) => null);
    _storage.delete(key: _keyMnemonic).catchError((_) => null);
    SharedPreferences.getInstance().then((prefs) {
      prefs.remove(_keyWalletState);
    }).catchError((_) {});

    ApiService().clearToken();

    _address = null;
    _mnemonic = null;
    _isVerified = false;
    _error = null;
    _pendingMnemonic = null;
    notifyListeners();
  }

  // ── Chain Management ──

  void switchChain(int chainId) {
    _activeChainId = chainId;
    notifyListeners();
    SharedPreferences.getInstance().then((prefs) {
      prefs.setInt('tpix_trade_chain_id', chainId);
    }).catchError((_) {});
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
    }
  }

  void _registerWithBackend(String address) {
    ApiService()
        .walletConnect(
          walletAddress: address,
          chainId: _activeChainId,
        )
        .then((_) => verifyWithBackend())
        .catchError((_) => false);
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
    return '{"language":"$_language","currency":"$_currency",'
        '"defaultPair":"$_defaultPair","defaultOrderType":"$_defaultOrderType",'
        '"slippage":$_slippage,"biometricEnabled":$_biometricEnabled,'
        '"pushNotifications":$_pushNotifications}';
  }

  void _settingsFromJson(String json) {
    try {
      // Simple parser — avoid importing dart:convert just for this
      if (json.contains('"language":"th"')) _language = 'th';
      if (json.contains('"currency":"THB"')) _currency = 'THB';
      final pairMatch = RegExp(r'"defaultPair":"([^"]+)"').firstMatch(json);
      if (pairMatch != null) _defaultPair = pairMatch.group(1)!;
      final orderMatch = RegExp(r'"defaultOrderType":"([^"]+)"').firstMatch(json);
      if (orderMatch != null) _defaultOrderType = orderMatch.group(1)!;
      final slipMatch = RegExp(r'"slippage":([\d.]+)').firstMatch(json);
      if (slipMatch != null) _slippage = double.tryParse(slipMatch.group(1)!) ?? 0.5;
      if (json.contains('"biometricEnabled":true')) _biometricEnabled = true;
      if (json.contains('"pushNotifications":false')) _pushNotifications = false;
    } catch (_) {
      // Use defaults
    }
  }
}
