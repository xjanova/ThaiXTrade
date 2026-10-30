/// TPIX TRADE — สะพาน USDT: คุยกับเว็บ (อ่านอย่างเดียว) + ตรวจบนเชนก่อนส่ง
///
/// พอร์ตจาก TPIX Wallet (lib/services/bridge_service.dart) — เฉพาะสะพาน USDT
/// (สะพาน TPIX ⇄ wTPIX ปิดอยู่ จึงไม่มีในแอปเทรด)
///
///   ฝาก: transfer(vault, จำนวน) ของ USDT จริงบนเชนต้นทาง (ตอนนี้เปิดแค่ BSC)
///        → เซิร์ฟเวอร์ mint USDT_TPIX ให้ที่อยู่เดียวกันบนเชน 4289
///   ถอน: USDT_TPIX.bridgeBurn(จำนวน, เชนปลายทาง, ที่อยู่) บนเชน 4289 (แก๊ส 0 · legacy tx)
///        → เซิร์ฟเวอร์จ่าย USDT จริงบนเชนปลายทาง หักค่าธรรมเนียมถอน
///
/// แอป "ไม่แจ้ง" เซิร์ฟเวอร์ว่าโอนแล้ว — ตัวสแกนของเซิร์ฟเวอร์อ่านจากเชนเอง แอปแค่ถามสถานะด้วย tx hash
/// (ค่า bridge ใน /api/v1/fees เป็นของสะพานรุ่นเก่าที่ถูกถอดแล้ว — หน้านี้ไม่อ่านมันอีก)
///
/// ด่านความปลอดภัย (fail-closed):
///   - ฟอร์มเปิดเมื่อเว็บบอก enabled และที่อยู่สัญญาทุกตัวครบ + ถูกรูป + chain id/ทศนิยมตรงกับที่แอปรู้จัก
///   - เชนฝากต้องเป็นเชนที่แอปมี RPC ตรึงไว้เอง ([ChainConfig.all]) และ USDT ที่เว็บบอกต้องตรงกับ
///     ที่อยู่/ทศนิยมที่แอปรู้จัก — ไม่ใช้ RPC หรือที่อยู่โทเค็นที่เว็บส่งมาแบบเชื่อทันที
///   - ก่อนให้ผู้ใช้ยืนยันทุกครั้ง ([BridgeOnChain.prepare]): RPC ต้องตอบ chain id ตรง
///     · ทศนิยมโทเค็นบนเชนต้องตรงกับเว็บ · ยอดพอ · eth_estimateGas ผ่าน · เหรียญหลักพอจ่ายแก๊ส
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:web3dart/json_rpc.dart' show RPCError;
import 'package:web3dart/web3dart.dart';

import '../core/constants/api_constants.dart';
import '../models/chain_config.dart';
import 'bridge_rules.dart';
import 'rpc_failover.dart';

// ════════════════════════════════════════════════════════════════
//  ข้อผิดพลาดของ API
// ════════════════════════════════════════════════════════════════

/// ติดต่อเว็บไม่ได้ / ตอบผิดรูป (เน็ตหลุด, timeout, 5xx, หน้า HTML ของ Cloudflare)
class BridgeUnavailableException implements Exception {
  const BridgeUnavailableException();

  @override
  String toString() => 'Bridge server unavailable';
}

/// เว็บจำกัดความถี่ (429) — หยุดถามรอบนี้ ลองใหม่รอบหน้า
class BridgeRateLimitedException implements Exception {
  const BridgeRateLimitedException();

  @override
  String toString() => 'Bridge server rate limited';
}

// ════════════════════════════════════════════════════════════════
//  เชนที่แอปเซ็นธุรกรรมฝากได้
// ════════════════════════════════════════════════════════════════

class BridgeChains {
  BridgeChains._();

  /// ChainConfig ของเชนนั้นแบบตรงตัว — ห้ามใช้ ChainConfig.byId (ไม่รู้จัก = คืนเชน TPIX แทน!)
  static ChainConfig? config(int chainId) {
    for (final c in ChainConfig.all) {
      if (c.chainId == chainId) return c;
    }
    return null;
  }

  /// แอปมี RPC ของเชนนี้ ให้เซ็นธุรกรรมฝากได้ (ไม่ใช่เชน TPIX เอง)
  static bool canDepositFrom(int chainId) => chainId != bridgeTpixChainId && config(chainId) != null;

  /// USDT ที่แอปรู้จักบนเชนนั้น — เว็บบอกที่อยู่/ทศนิยมไม่ตรงกับนี้ = ไม่เปิดให้ฝากจากเชนนั้น
  static TokenDef? knownUsdt(int chainId) {
    final cfg = config(chainId);
    if (cfg == null) return null;
    for (final t in cfg.knownTokens) {
      if (t.symbol == 'USDT') return t;
    }
    return null;
  }
}

// ════════════════════════════════════════════════════════════════
//  ข้อมูลจากเว็บ — ตรวจทุกช่องก่อนใช้
// ════════════════════════════════════════════════════════════════

String? _addr(Object? v) => isBridgeAddress(v) ? (v as String).trim() : null;

int _int(Object? v, [int fallback = 0]) => bridgeInt(v) ?? fallback;

/// ข้อความสั้นๆ จากเว็บ (ชื่อเชน/สัญลักษณ์) — ตัดอักขระควบคุม + จำกัดความยาว
String? _label(Object? v, [int max = 40]) {
  if (v is! String) return null;
  final clean = v.replaceAll(RegExp(r'[\u0000-\u001F\u007F\u200B-\u200F\u202A-\u202E\u2066-\u2069]'), '').trim();
  if (clean.isEmpty) return null;
  return clean.length > max ? clean.substring(0, max) : clean;
}

Map<String, dynamic> _map(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};

/// ทศนิยมของโทเค็นที่รับได้ (กันค่าแปลกๆ ทำให้คำนวณหน่วยพัง)
bool _decimalsOk(int d) => d >= 0 && d <= 36;

/// หนึ่งเชนของสะพาน USDT
class UsdtBridgeChain {
  final int chainId;
  final String name;
  final String nativeSymbol;
  final bool serverEnabled;

  /// สัญญา USDT บนเชนนี้ — null = เว็บส่งมาผิดรูป (เชนนี้ใช้ไม่ได้)
  final String? usdtAddress;
  final int usdtDecimals;
  final String usdtSymbol;
  final String? explorer;

  /// ค่าธรรมเนียมถอน (USDT) — ข้อความจากเว็บ · '' = อ่านไม่ออก (ถอนไปเชนนี้ไม่ได้)
  final String withdrawFee;
  final int etaMinutes;

  const UsdtBridgeChain({
    required this.chainId,
    required this.name,
    required this.nativeSymbol,
    required this.serverEnabled,
    required this.usdtAddress,
    required this.usdtDecimals,
    required this.usdtSymbol,
    required this.explorer,
    required this.withdrawFee,
    required this.etaMinutes,
  });

  factory UsdtBridgeChain.fromServer(Map<String, dynamic> j) {
    final feeText = bridgeText(j['withdraw_fee']) ?? '';
    return UsdtBridgeChain(
      chainId: _int(j['chain_id']),
      name: _label(j['name']) ?? '—',
      nativeSymbol: _label(j['native'], 10) ?? '',
      // ต้องเป็น true จริงเท่านั้น — "true"/1/ไม่มีช่อง = ปิด
      serverEnabled: j['enabled'] == true,
      usdtAddress: _addr(j['usdt_address']),
      usdtDecimals: _int(j['usdt_decimals'], -1),
      usdtSymbol: _label(j['usdt_symbol'], 12) ?? 'USDT',
      explorer: safeHttpsUrl(j['explorer']),
      // ค่าธรรมเนียมอ่านไม่ออก = ถอนไปเชนนี้ไม่ได้ (ไม่เดาว่าเป็น 0)
      withdrawFee: parseAmountToUnits(feeText, usdtTpixDecimals) != null ? feeText : '',
      etaMinutes: _int(j['eta_minutes'], 10),
    );
  }

  bool get _contractOk => usdtAddress != null && _decimalsOk(usdtDecimals) && chainId > 0;

  /// แอปมี RPC ของเชนนี้ ให้เซ็นธุรกรรมฝากได้
  bool get appSupported => BridgeChains.canDepositFrom(chainId);

  /// USDT ที่เว็บบอกตรงกับที่แอปรู้จัก (ที่อยู่ + ทศนิยม) — ไม่ตรง = อาจเป็นสัญญาปลอม ไม่เปิดเชนนี้
  bool get matchesAppToken {
    final known = BridgeChains.knownUsdt(chainId);
    final addr = usdtAddress;
    if (known == null || addr == null) return false;
    return known.address.toLowerCase() == addr.toLowerCase() && known.decimals == usdtDecimals;
  }

  /// ฝากจากแอปได้: เว็บเปิดเชนนี้ + สัญญาถูกรูป + แอปรู้จักเชนและโทเค็นตรงกัน
  bool get depositable => serverEnabled && _contractOk && appSupported && matchesAppToken;

  /// ถอนไปเชนนี้ได้: เว็บเปิดเชนนี้ + ค่าธรรมเนียมอ่านออก (แอปไม่ต้องเซ็นอะไรบนเชนปลายทาง)
  bool get withdrawable =>
      serverEnabled && chainId > 0 && chainId != bridgeTpixChainId && withdrawFee.isNotEmpty;
}

/// /api/v1/bridge/info (สะพาน USDT)
class UsdtBridgeInfo {
  final bool enabled;
  final bool installed;

  /// maintenance / safety_hold / null
  final String? pausedReason;
  final String? vaultAddress;
  final int tpixChainId;
  final String? usdtTpix;
  final int usdtTpixDecimalsFromServer;
  final String? tpixExplorer;
  final List<UsdtBridgeChain> chains;
  final Map<String, dynamic> limits;

  /// ฝากต่อครั้งได้สูงสุดจริง = min(วงเงินเว็บ, maxPerTx บนสัญญา) — เกินนี้ mint ไม่ได้เลย
  final String? depositMaxPerTx;

  /// เพดานบนสัญญา minter (USDT) — null = อ่านไม่ได้
  final String? minterMaxPerTx;
  final String? minterDailyCap;
  final String? minterRemainingToday;
  final bool? minterPaused;

  const UsdtBridgeInfo({
    required this.enabled,
    required this.installed,
    required this.pausedReason,
    required this.vaultAddress,
    required this.tpixChainId,
    required this.usdtTpix,
    required this.usdtTpixDecimalsFromServer,
    required this.tpixExplorer,
    required this.chains,
    required this.limits,
    required this.depositMaxPerTx,
    required this.minterMaxPerTx,
    required this.minterDailyCap,
    required this.minterRemainingToday,
    required this.minterPaused,
  });

  factory UsdtBridgeInfo.fromServer(Map<String, dynamic> d) {
    final tpix = _map(d['tpix']);
    final minter = _map(d['minter']);
    final chains = <UsdtBridgeChain>[];
    for (final c in (d['chains'] is List ? d['chains'] as List : const [])) {
      if (c is Map) chains.add(UsdtBridgeChain.fromServer(Map<String, dynamic>.from(c)));
    }
    // เก็บเฉพาะค่าวงเงินที่อ่านออก — ค่าแปลกปลอมไม่ถูกส่งต่อไปถึงหน้าจอ
    final limits = <String, dynamic>{};
    _map(d['limits']).forEach((k, v) {
      final t = bridgeAmountText(v);
      if (t != null) limits[k] = t;
    });
    final paused = minter['paused'];
    return UsdtBridgeInfo(
      enabled: d['enabled'] == true,
      installed: d['installed'] == true,
      pausedReason: _label(d['paused_reason'], 30),
      vaultAddress: _addr(d['vault_address']),
      tpixChainId: _int(tpix['chain_id']),
      usdtTpix: _addr(tpix['usdt_tpix']),
      usdtTpixDecimalsFromServer: _int(tpix['usdt_decimals'], -1),
      tpixExplorer: safeHttpsUrl(tpix['explorer']),
      chains: chains.take(20).toList(),
      limits: limits,
      depositMaxPerTx: bridgeAmountText(d['deposit_max_per_tx']),
      minterMaxPerTx: bridgeAmountText(minter['max_per_tx']),
      minterDailyCap: bridgeAmountText(minter['daily_cap']),
      minterRemainingToday: bridgeAmountText(minter['remaining_today']),
      minterPaused: paused is bool ? paused : null,
    );
  }

  /// ที่อยู่สัญญา + เชนตรงกับที่แอปรู้จัก (ไม่ครบ/ผิด = ไม่เปิดฟอร์ม แม้เว็บบอกเปิด)
  bool get configOk =>
      vaultAddress != null &&
      usdtTpix != null &&
      tpixChainId == bridgeTpixChainId &&
      usdtTpixDecimalsFromServer == usdtTpixDecimals;

  /// เปิดให้กรอกฟอร์ม (fail-closed)
  bool get open => enabled && configOk;

  /// เชนที่เลือกฝากได้ (เว็บเปิด + แอปรองรับ) — เชนที่ปิดอยู่ไม่ถูกเสนอเลย
  List<UsdtBridgeChain> get depositChains => chains.where((c) => c.depositable).toList();

  /// เชนปลายทางที่ถอนไปได้ (เว็บเปิด) — เชนที่ปิดอยู่ไม่ถูกเสนอเลย
  List<UsdtBridgeChain> get withdrawChains => chains.where((c) => c.withdrawable).toList();

  /// ค่าธรรมเนียมฝาก (USDT) — ไม่มีค่า = 0
  String get depositFee => bridgeText(limits['deposit_fee']) ?? '0';

  UsdtBridgeChain? chain(int chainId) {
    for (final c in chains) {
      if (c.chainId == chainId) return c;
    }
    return null;
  }

  String chainName(int? chainId) {
    if (chainId == null) return '—';
    if (chainId == bridgeTpixChainId) return 'TPIX Chain';
    return chain(chainId)?.name ?? BridgeChains.config(chainId)?.name ?? '#$chainId';
  }

  String? explorerFor(int chainId) => chainId == bridgeTpixChainId
      ? (tpixExplorer ?? ChainConfig.tpix.explorerUrl)
      : (chain(chainId)?.explorer ?? BridgeChains.config(chainId)?.explorerUrl);
}

/// หนึ่งแถวในประวัติสะพานของกระเป๋า
class BridgeHistoryRow {
  /// deposit / withdraw
  final String kind;
  final String state;
  final String? amount;

  /// ฝาก: เชนต้นทาง · ถอน: เชนปลายทาง
  final int? chainId;
  final String? txHash;

  const BridgeHistoryRow({required this.kind, required this.state, this.amount, this.chainId, this.txHash});
}

String? _hash(Object? v) => v is String && isTxHash(v) ? v.trim().toLowerCase() : null;

int? _chainId(Object? v) {
  final i = bridgeInt(v);
  return i != null && i > 0 ? i : null;
}

// ════════════════════════════════════════════════════════════════
//  API (อ่านอย่างเดียว)
// ════════════════════════════════════════════════════════════════

class BridgeApi {
  BridgeApi({http.Client Function()? clientFactory, String? baseUrl})
      : _clientFactory = clientFactory ?? http.Client.new,
        _base = baseUrl ?? '${ApiConstants.baseUrl}/bridge';

  static final BridgeApi instance = BridgeApi();

  /// หน้า Proof of Reserves บนเว็บ (เปิดในเบราว์เซอร์)
  static const String reservesUrl = 'https://tpix.online/bridge/reserves';

  final http.Client Function() _clientFactory;
  final String _base;

  static const _timeout = Duration(seconds: 12);

  Future<Map<String, dynamic>> _get(String path) async {
    final client = _clientFactory();
    try {
      final res = await client.get(Uri.parse('$_base$path'), headers: const {
        'Accept': 'application/json',
        // ไม่มี User-Agent = Cloudflare หน้า tpix.online ตอบหน้า HTML บล็อก
        'User-Agent': 'TPIX-Trade (Flutter)',
      }).timeout(_timeout);
      if (res.statusCode == 429) throw const BridgeRateLimitedException();
      if (res.statusCode < 200 || res.statusCode >= 300) throw const BridgeUnavailableException();
      final dynamic body;
      try {
        body = jsonDecode(utf8.decode(res.bodyBytes));
      } on FormatException {
        throw const BridgeUnavailableException();
      }
      if (body is! Map || body['success'] != true || body['data'] is! Map) {
        throw const BridgeUnavailableException();
      }
      return Map<String, dynamic>.from(body['data'] as Map);
    } on BridgeUnavailableException {
      rethrow;
    } on BridgeRateLimitedException {
      rethrow;
    } catch (e) {
      // ไม่พิมพ์ข้อความเต็ม — อาจมี URL/รายละเอียดภายใน
      debugPrint('BridgeApi$path: ${e.runtimeType}');
      throw const BridgeUnavailableException();
    } finally {
      client.close();
    }
  }

  Future<UsdtBridgeInfo> fetchInfo() async => UsdtBridgeInfo.fromServer(await _get('/info'));

  /// สถานะของการฝาก (ธุรกรรมบนเชนต้นทาง)
  Future<BridgeStatusView> fetchDepositStatus(int chainId, String txHash) async {
    if (!isTxHash(txHash) || chainId <= 0) throw const BridgeUnavailableException();
    return BridgeStatusView.fromServer(await _get('/status/$chainId/${txHash.trim().toLowerCase()}'));
  }

  /// สถานะการถอน (burn บนเชน 4289)
  Future<BridgeStatusView> fetchWithdrawal(String txHash) async {
    if (!isTxHash(txHash)) throw const BridgeUnavailableException();
    return BridgeStatusView.fromServer(await _get('/withdrawals/${txHash.trim().toLowerCase()}'));
  }

  /// ประวัติสะพานของกระเป๋า (ฝาก + ถอน ล่าสุด)
  Future<List<BridgeHistoryRow>> fetchHistory(String wallet) async {
    if (!isBridgeAddress(wallet)) return const [];
    final d = await _get('/history/${wallet.trim().toLowerCase()}');
    return [
      for (final r in (d['deposits'] is List ? d['deposits'] as List : const []))
        if (r is Map)
          BridgeHistoryRow(
            kind: usdtDeposit,
            state: usdtDepositRowState(bridgeState(r['status'])),
            amount: bridgeAmountText(r['amount']),
            chainId: _chainId(r['source_chain_id']),
            txHash: _hash(r['tx_hash']),
          ),
      for (final r in (d['withdrawals'] is List ? d['withdrawals'] as List : const []))
        if (r is Map)
          BridgeHistoryRow(
            kind: usdtWithdraw,
            state: usdtWithdrawalRowState(bridgeState(r['status'])),
            amount: bridgeAmountText(r['amount']),
            chainId: _chainId(r['target_chain_id']),
            txHash: _hash(r['tx_hash']),
          ),
    ].take(50).toList();
  }
}

// ════════════════════════════════════════════════════════════════
//  ธุรกรรมบนเชน
// ════════════════════════════════════════════════════════════════

/// ธุรกรรมที่ผ่านด่านตรวจแล้ว พร้อมให้ผู้ใช้ยืนยัน + ส่งผ่าน WalletProvider
class BridgeTxPlan {
  final int chainId;
  final String from;
  final String to;
  final Uint8List data;
  final int gasLimit;
  final BigInt gasPrice;
  final String nativeSymbol;

  const BridgeTxPlan({
    required this.chainId,
    required this.from,
    required this.to,
    required this.data,
    required this.gasLimit,
    required this.gasPrice,
    required this.nativeSymbol,
  });

  /// ค่าแก๊สสูงสุดที่อาจจ่าย (หน่วยเล็กสุดของเหรียญหลัก) — เชน TPIX = 0
  BigInt get maxFee => gasPrice * BigInt.from(gasLimit);
}

/// ERC-20 ที่ธุรกรรมจะหักออกจากกระเป๋า — ใช้ตรวจทศนิยม + ยอดก่อนเซ็น
typedef BridgeSpend = ({String token, BigInt amount, int decimals});

/// สิ่งที่ด่านตรวจต้องอ่านจากเชน — แยกเป็น interface ให้เทสต์ใส่เชนปลอมได้
abstract class BridgeChainReader {
  Future<BigInt> chainId();
  Future<int> tokenDecimals(String token);
  Future<BigInt> tokenBalance(String token, String owner);
  Future<BigInt> nativeBalance(String owner);
  Future<BigInt> estimateGas({required String from, required String to, required Uint8List data});
  Future<BigInt> gasPrice();
}

class BridgeTx {
  BridgeTx._();

  // ── ABI (เฉพาะฟังก์ชันที่สะพานใช้ — ตรงกับสัญญาใน TPIX-Coin/contracts) ──
  //   USDT_TPIX.bridgeBurn(uint256 amount, uint256 targetChainId, string targetAddress)
  static const _abiJson = '''
[
 {"type":"function","name":"bridgeBurn","stateMutability":"nonpayable","inputs":[{"name":"amount","type":"uint256"},{"name":"targetChainId","type":"uint256"},{"name":"targetAddress","type":"string"}],"outputs":[]},
 {"type":"function","name":"balanceOf","stateMutability":"view","inputs":[{"name":"owner","type":"address"}],"outputs":[{"name":"","type":"uint256"}]},
 {"type":"function","name":"decimals","stateMutability":"view","inputs":[],"outputs":[{"name":"","type":"uint8"}]}
]
''';

  static final ContractAbi abi = ContractAbi.fromJson(_abiJson, 'TPIXBridge');

  static ContractFunction fn(String name) => abi.functions.firstWhere((f) => f.name == name);

  /// ERC-20 transfer(vault, จำนวน)
  static Uint8List encodeTransfer(String to, BigInt amount) => encodeErc20Transfer(to, amount);

  static Uint8List encodeBridgeBurn(BigInt amount, int targetChainId, String targetAddress) =>
      fn('bridgeBurn').encodeCall([amount, BigInt.from(targetChainId), targetAddress.trim()]);

  /// gas ที่ประเมินได้ + 30% — เท่ากับที่ WalletProvider ใช้ตอนเซ็นด้วยกระเป๋าในแอป
  static int gasWithMargin(BigInt estimated) => (estimated * BigInt.from(13) ~/ BigInt.from(10)).toInt();

  /// ด่านก่อนให้ผู้ใช้ยืนยัน — ยังไม่มีอะไรถูกเซ็นหรือส่ง
  ///
  /// [spend] = ERC-20 ที่จะถูกหัก (ทศนิยมบนเชนต้องตรงกับเว็บ + ยอดพอ)
  static Future<BridgeTxPlan> check(
    BridgeChainReader r, {
    required int chainId,
    required String nativeSymbol,
    required String from,
    required String to,
    required Uint8List data,
    BridgeSpend? spend,
  }) async {
    if (!isBridgeAddress(from) || !isBridgeAddress(to)) {
      throw const BridgeCheckException('bridge.errNotConfigured');
    }

    // RPC ต้องเป็นเชนที่จะส่งจริง — ไม่ตรง = ไม่ส่ง (ยอด/nonce/แก๊สผิดทั้งหมด)
    final id = await r.chainId();
    if (id != BigInt.from(chainId)) throw const BridgeCheckException('bridge.errWrongChain');

    if (spend != null) {
      final int onChainDecimals;
      final BigInt balance;
      try {
        onChainDecimals = await r.tokenDecimals(spend.token);
        balance = await r.tokenBalance(spend.token, from);
      } catch (e) {
        // ส่งไม่ถึงโหนด = ให้ RpcPool ลองตัวสำรอง · โหนดตอบแต่อ่านไม่ได้ = ไม่ใช่โทเค็นที่เว็บบอก
        if (RpcFailover.shouldFailover(e)) rethrow;
        throw const BridgeCheckException('bridge.errTokenMismatch');
      }
      if (onChainDecimals != spend.decimals) {
        // เว็บบอกทศนิยมไม่ตรงกับสัญญาจริง = ห้ามเดา (ส่งผิดหลักได้เป็นพันเท่า)
        throw const BridgeCheckException('bridge.errTokenMismatch');
      }
      if (balance < spend.amount) {
        throw const BridgeCheckException('bridge.errInsufficient');
      }
    }

    // จำลองบนเชนก่อน — สัญญาจะปฏิเสธ (หยุดอยู่/เกินเพดาน) ก็บอกเลย ไม่ต้องไปเจอ revert ตอนส่ง
    final BigInt estimated;
    try {
      estimated = await r.estimateGas(from: from, to: to, data: data);
    } on RPCError catch (e) {
      final m = e.message.toLowerCase();
      if (m.contains('paused')) throw const BridgeCheckException('bridge.errPausedOnChain');
      if (m.contains('insufficient') || m.contains('exceeds balance')) {
        throw const BridgeCheckException('bridge.errInsufficient');
      }
      throw const BridgeCheckException('bridge.errWouldRevert');
    }
    if (estimated <= BigInt.zero) throw const BridgeCheckException('bridge.errWouldRevert');
    final gas = gasWithMargin(estimated);

    // เชน TPIX ตอบ 0 (ค่าแก๊สตั้งใน genesis) — อ่านจากเชนจริงเหมือนตัวส่ง ไม่เดาเอง
    final price = await r.gasPrice();
    if (price > BigInt.zero) {
      final need = price * BigInt.from(gas);
      if (await r.nativeBalance(from) < need) {
        throw BridgeCheckException('bridge.errGasNeed', {'amount': shortNative(need), 'symbol': nativeSymbol});
      }
    }

    return BridgeTxPlan(
      chainId: chainId,
      from: from,
      to: to,
      data: data,
      gasLimit: gas,
      gasPrice: price,
      nativeSymbol: nativeSymbol,
    );
  }

  /// ค่าแก๊สแบบย่อ (เหรียญหลัก 18 ทศนิยม) — ปัดขึ้นที่ทศนิยม 6 ตำแหน่ง ไม่ให้ดูน้อยกว่าจริง
  static String shortNative(BigInt wei) {
    final unit = BigInt.from(10).pow(12);
    final rounded = ((wei + unit - BigInt.one) ~/ unit) * unit;
    return formatUnitsPlain(rounded, 18);
  }

  /// ค่าแก๊สสูงสุดของแผน (ไว้โชว์ในกล่องยืนยัน)
  static String feeText(BridgeTxPlan plan) => shortNative(plan.maxFee);
}

/// อ่านเชนจริงผ่าน RPC ที่ตรึงในแอป ([RpcPool] สลับตัวสำรองเองเมื่อส่งไม่ถึง)
///
/// เทสต์/หน้าจอทดสอบ implements คลาสนี้เพื่อไม่ยิงเชนจริง
class BridgeOnChain {
  const BridgeOnChain();

  Future<T> _withReader<T>(int chainId, Future<T> Function(BridgeChainReader r) fn) {
    final cfg = BridgeChains.config(chainId);
    if (cfg == null) throw const BridgeCheckException('bridge.errChainUnsupported');
    // ทุกการอ่านของหนึ่งรอบอยู่บน RPC ตัวเดียวกัน — ตัวที่เพิ่งตอบ chain id ถูก
    return RpcPool.forChain(cfg).withClient((c) => fn(_Web3Reader(c)), timeout: const Duration(seconds: 25));
  }

  /// ยอด ERC-20 — อ่านไม่ได้ = throw (ห้ามถือเป็น 0)
  Future<BigInt> tokenBalance(int chainId, String token, String owner) =>
      _withReader(chainId, (r) => r.tokenBalance(token, owner));

  /// ด่านก่อนยืนยัน — ข้อผิดพลาดทุกแบบออกมาเป็น [BridgeCheckException] (ห้าม Exception ดิบถึงผู้ใช้)
  Future<BridgeTxPlan> prepare({
    required int chainId,
    required String from,
    required String to,
    required Uint8List data,
    BridgeSpend? spend,
  }) async {
    final cfg = BridgeChains.config(chainId);
    if (cfg == null) throw const BridgeCheckException('bridge.errChainUnsupported');
    try {
      return await _withReader(
        chainId,
        (r) => BridgeTx.check(r,
            chainId: chainId, nativeSymbol: cfg.symbol, from: from, to: to, data: data, spend: spend),
      );
    } on BridgeCheckException {
      rethrow;
    } on RPCError {
      throw const BridgeCheckException('bridge.errWouldRevert');
    } catch (e) {
      debugPrint('BridgeOnChain.prepare: ${e.runtimeType}');
      throw const BridgeCheckException('bridge.errNetwork');
    }
  }
}

/// eth_call ตอบกลับมาแต่ผลไม่ใช่ uint ที่คาด (ที่อยู่ไม่ใช่สัญญา/ไม่ใช่ ERC-20)
/// — แยกจาก FormatException (หน้า HTML ของ proxy) ซึ่ง RpcPool ใช้สลับไปตัวสำรอง
class BridgeBadCallResult implements Exception {
  const BridgeBadCallResult();
}

class _Web3Reader implements BridgeChainReader {
  final Web3Client _c;

  _Web3Reader(this._c);

  DeployedContract _token(String address) => DeployedContract(BridgeTx.abi, EthereumAddress.fromHex(address));

  BigInt _uint(List<dynamic> out) {
    final v = out.isEmpty ? null : out.first;
    if (v is! BigInt) throw const BridgeBadCallResult();
    return v;
  }

  @override
  Future<BigInt> chainId() => _c.getChainId();

  @override
  Future<int> tokenDecimals(String token) async {
    final c = _token(token);
    final d = _uint(await _c.call(contract: c, function: c.function('decimals'), params: const []));
    if (d > BigInt.from(255)) throw const BridgeBadCallResult();
    return d.toInt();
  }

  @override
  Future<BigInt> tokenBalance(String token, String owner) async {
    final c = _token(token);
    return _uint(await _c.call(
      contract: c,
      function: c.function('balanceOf'),
      params: [EthereumAddress.fromHex(owner)],
    ));
  }

  @override
  Future<BigInt> nativeBalance(String owner) async =>
      (await _c.getBalance(EthereumAddress.fromHex(owner))).getInWei;

  @override
  Future<BigInt> estimateGas({required String from, required String to, required Uint8List data}) =>
      _c.estimateGas(sender: EthereumAddress.fromHex(from), to: EthereumAddress.fromHex(to), data: data);

  @override
  Future<BigInt> gasPrice() async => (await _c.getGasPrice()).getInWei;
}
