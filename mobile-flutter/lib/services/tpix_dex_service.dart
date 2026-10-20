/// TPIX TRADE — TPIX DEX Service (mobile)
/// เทรดจริงบนเชน TPIX (4289) ผ่าน TPIXDEXRouter02 (AMM แบบ Uniswap V2)
/// mirror มาจาก useTpixDex.js ฝั่งเว็บ ให้พฤติกรรมสองฝั่งตรงกันที่สุด
///
///  - ที่อยู่สัญญา **มาจากทะเบียนบนเซิร์ฟเวอร์** (`/api/v1/dex/config`) ไม่ hardcode
///    ที่อยู่ในแอปเด็ดขาด เพราะ deploy ใหม่แล้วแอปเก่าจะชี้ไปที่ตายทันที
///  - fail-closed: เซิร์ฟเวอร์ยังไม่ตอบว่า ready = ทุก action คืน error ไม่เดา address
///  - ราคา/ยอด/allowance อ่านจาก RPC ของเชน TPIX ตรง ไม่ขึ้นกับเชนที่กระเป๋าอยู่
///    (ผ่าน [RpcPool] — rpc.tpix.online โดน Cloudflare บล็อกเมื่อไรก็สลับไป rpc1 เอง)
///  - ค่าธรรมเนียม 0.3% **อยู่ในพูล** (LP + feeTo) ไม่มีธุรกรรมโอนค่าธรรมเนียมแยก
///    ต่างจากฝั่ง BSC ที่ต้องโอนใบที่สอง — จึงไม่มี fee reserve ที่นี่
///  - price impact คิดจาก reserve ของพูลแบบเดียวกับเว็บเป๊ะ ๆ และใช้เพดาน 10% เท่ากัน
///    (กติกาต้องเหมือนกันทุกแพลตฟอร์ม ไม่งั้นไม้เดียวกันผ่านในแอปแต่โดนบล็อกบนเว็บ)
///
/// การเซ็น/ส่งธุรกรรมเป็นหน้าที่ของ caller (WalletProvider) ผ่าน [DexTxSender]
/// — service นี้ไม่แตะ private key เด็ดขาด (กติกาเดียวกับ BscSwapService)
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:web3dart/json_rpc.dart' show RPCError;
import 'package:web3dart/web3dart.dart';

import '../models/chain_config.dart';
import 'api_service.dart';
// ใช้ชนิดข้อมูลร่วมกับฝั่ง BSC เพื่อให้หน้าจอถือผลลัพธ์แบบเดียวกันได้
// (VerifiedToken = โทเคนที่ตรวจ decimals กับเชนแล้ว · SwapException = ข้อความ 2 ภาษา)
import 'bsc_swap_service.dart' show SwapException, VerifiedToken;
import 'rpc_failover.dart';

/// ค่าธรรมเนียมของพูล UniV2 — เข้าพูลทั้งก้อน ไม่ได้หักเพิ่มจากผู้ใช้
const double kTpixPoolFeePct = 0.3;

/// เพดาน price impact ต่อไม้ — **ต้องเท่ากับเว็บ** (Trade.vue: `quote.priceImpact > 10` = ไม่ส่ง)
const double kTpixMaxPriceImpactPct = 10;

/// ข้อความเมื่อเชนติดต่อไม่ได้ (ทุก RPC ส่งไม่ถึง) — แยกจาก "ไม่มีสภาพคล่อง" ให้ชัด
const SwapException kTpixChainUnreachable = SwapException(
  'TPIX Chain is unreachable right now — please try again.',
  'เชื่อมต่อเชน TPIX ไม่ได้ชั่วคราว ลองใหม่อีกครั้ง',
);

/// ที่อยู่สัญญาชุด DEX ที่เซิร์ฟเวอร์ยืนยันแล้วว่ามีโค้ดอยู่บนเชนจริง
class TpixDexConfig {
  final bool ready;
  final int chainId;
  final String rpc;
  final String? wtpix;
  final String? usdt;
  final String? factory;
  final String? router;
  final List<String> missing;

  const TpixDexConfig({
    required this.ready,
    required this.chainId,
    required this.rpc,
    this.wtpix,
    this.usdt,
    this.factory,
    this.router,
    this.missing = const [],
  });

  factory TpixDexConfig.fromJson(Map<String, dynamic> json) => TpixDexConfig(
        ready: json['ready'] == true,
        chainId: (json['chainId'] as num?)?.toInt() ?? 4289,
        rpc: (json['rpc'] as String?) ?? ChainConfig.tpix.rpcUrl,
        wtpix: json['WTPIX'] as String?,
        usdt: json['USDT'] as String?,
        factory: json['FACTORY'] as String?,
        router: json['ROUTER'] as String?,
        missing: ((json['missing'] as List<dynamic>?) ?? const [])
            .map((e) => e.toString())
            .toList(),
      );

  static const notReady = TpixDexConfig(
    ready: false,
    chainId: 4289,
    rpc: 'https://rpc1.tpix.online',
  );
}

/// ผล quote จาก router จริง — เก็บ wei ไว้ใช้ตอน execute ตรง ๆ
class TpixDexQuote {
  final VerifiedToken fromToken;
  final VerifiedToken toToken;
  final double amountIn;
  final double netOutput;
  final double minReceived;
  final double slippage;

  /// ราคาที่ไม้นี้ดันพูลไป (%) — 0 = พูลลึกพอจนแทบไม่ขยับ
  final double priceImpact;

  final BigInt amountInWei;
  final BigInt rawAmountOut;
  final List<EthereumAddress> path;
  final String router;

  /// reserve ของพูลฮอปแรก (ฝั่งที่จ่ายเข้า/ฝั่งที่ได้ออก) — null = อ่านไม่ได้หรือวิ่ง 2 ฮอป
  /// ใช้คำนวณ "สั่งได้สูงสุดเท่าไร" ตอนไม้ใหญ่เกินเพดาน
  final BigInt? reserveIn;
  final BigInt? reserveOut;

  const TpixDexQuote({
    required this.fromToken,
    required this.toToken,
    required this.amountIn,
    required this.netOutput,
    required this.minReceived,
    required this.slippage,
    required this.priceImpact,
    required this.amountInWei,
    required this.rawAmountOut,
    required this.path,
    required this.router,
    this.reserveIn,
    this.reserveOut,
  });

  /// ค่าธรรมเนียมที่พูลกิน — แสดงให้ผู้ใช้เห็นเท่านั้น ไม่มีธุรกรรมแยก
  double get feeAmount => amountIn * (kTpixPoolFeePct / 100);

  double get feeRate => kTpixPoolFeePct;

  /// ไม้นี้เกินเพดานเดียวกับเว็บ — ส่งไม่ได้
  bool get exceedsImpactLimit => priceImpact > kTpixMaxPriceImpactPct;

  /// จ่ายเข้าได้มากสุดเท่าไร (หน่วย fromToken) โดยไม่ดันราคาเกินเพดาน — null = ไม่รู้ reserve
  double? get maxAmountInUnderLimit {
    final rIn = reserveIn;
    if (rIn == null) return null;
    return TpixDexService.fromWei(
        TpixDexService.maxAmountInForImpact(reserveIn: rIn), fromToken.decimals);
  }

  /// ได้ออกมากสุดเท่าไร (หน่วย toToken) โดยไม่ดันราคาเกินเพดาน — null = ไม่รู้ reserve
  double? get maxAmountOutUnderLimit {
    final rIn = reserveIn;
    final rOut = reserveOut;
    if (rIn == null || rOut == null) return null;
    final maxIn = TpixDexService.maxAmountInForImpact(reserveIn: rIn);
    return TpixDexService.fromWei(
        TpixDexService.amountOutFor(maxIn, rIn, rOut), toToken.decimals);
  }
}

class TpixDexSwapResult {
  final String txHash;
  final String explorerUrl;
  final bool confirmed;

  const TpixDexSwapResult({
    required this.txHash,
    required this.explorerUrl,
    required this.confirmed,
  });
}

/// ส่งธุรกรรมบนเชน TPIX — คืน tx hash หรือ null ถ้าผู้ใช้ปฏิเสธ/ส่งไม่ได้
typedef DexTxSender = Future<String?> Function({
  required String to,
  Uint8List? data,
  BigInt? value,
});

const String _erc20Abi = '''
[
  {"constant":true,"inputs":[],"name":"symbol","outputs":[{"name":"","type":"string"}],"type":"function"},
  {"constant":true,"inputs":[],"name":"decimals","outputs":[{"name":"","type":"uint8"}],"type":"function"},
  {"constant":true,"inputs":[{"name":"owner","type":"address"}],"name":"balanceOf","outputs":[{"name":"","type":"uint256"}],"type":"function"},
  {"constant":true,"inputs":[{"name":"owner","type":"address"},{"name":"spender","type":"address"}],"name":"allowance","outputs":[{"name":"","type":"uint256"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"spender","type":"address"},{"name":"value","type":"uint256"}],"name":"approve","outputs":[{"name":"","type":"bool"}],"type":"function"}
]
''';

const String _routerAbi = '''
[
  {"constant":true,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"path","type":"address[]"}],"name":"getAmountsOut","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"},
  {"constant":true,"inputs":[{"name":"amountOut","type":"uint256"},{"name":"path","type":"address[]"}],"name":"getAmountsIn","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactETHForTokens","outputs":[{"name":"amounts","type":"uint256[]"}],"payable":true,"type":"function"},
  {"constant":false,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactTokensForETH","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactTokensForTokens","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"}
]
''';

const String _factoryAbi = '''
[
  {"constant":true,"inputs":[{"name":"","type":"address"},{"name":"","type":"address"}],"name":"getPair","outputs":[{"name":"","type":"address"}],"type":"function"}
]
''';

const String _pairAbi = '''
[
  {"constant":true,"inputs":[],"name":"token0","outputs":[{"name":"","type":"address"}],"type":"function"},
  {"constant":true,"inputs":[],"name":"getReserves","outputs":[{"name":"_reserve0","type":"uint112"},{"name":"_reserve1","type":"uint112"},{"name":"_blockTimestampLast","type":"uint32"}],"type":"function"}
]
''';

class TpixDexService {
  TpixDexService._();
  static final TpixDexService _instance = TpixDexService._();
  factory TpixDexService() => _instance;

  static const String nativeAddress =
      '0x0000000000000000000000000000000000000000';

  final _erc20 = ContractAbi.fromJson(_erc20Abi, 'ERC20');
  final _routerAbiParsed = ContractAbi.fromJson(_routerAbi, 'TPIXDEXRouter02');
  final _factoryAbiParsed = ContractAbi.fromJson(_factoryAbi, 'TPIXDEXFactory');
  final _pairAbiParsed = ContractAbi.fromJson(_pairAbi, 'TPIXDEXPair');

  TpixDexConfig _config = TpixDexConfig.notReady;
  DateTime? _configAt;
  Future<TpixDexConfig>? _configInFlight;

  /// decimals ที่อ่านจากเชนแล้ว — ค่าคงที่ตลอดอายุสัญญา
  final Map<String, int> _decimalsCache = {};

  /// ที่อยู่พูล + token0 ต่อคู่โทเคน — ค่าคงที่ (พูลของคู่หนึ่งมีได้ตัวเดียว)
  final Map<String, ({String pair, String token0})> _pairCache = {};

  TpixDexConfig get lastConfig => _config;

  bool get ready => _config.ready;

  /// โหลดที่อยู่สัญญาจากทะเบียนบนเซิร์ฟเวอร์ (แคช 60 วิ · รวมคำขอที่ซ้อนกัน)
  /// ล้มเหลว = คงค่าเดิม และ ready ยังเป็นเท็จ → ไม่มีทางเทรดด้วยที่อยู่ที่เดา
  Future<TpixDexConfig> loadConfig({bool force = false}) {
    final fresh = _configAt != null &&
        DateTime.now().difference(_configAt!) < const Duration(seconds: 60);
    if (!force && fresh) return Future.value(_config);
    if (_configInFlight != null) return _configInFlight!;

    _configInFlight = ApiService().getDexConfig().then((json) {
      if (json != null) {
        _config = TpixDexConfig.fromJson(json);
        _configAt = DateTime.now();
      }
      return _config;
    }).catchError((_) => _config).whenComplete(() {
      _configInFlight = null;
    });

    return _configInFlight!;
  }

  Future<TpixDexConfig> _requireReady() async {
    final cfg = await loadConfig();
    if (!cfg.ready || cfg.router == null || cfg.wtpix == null) {
      throw const SwapException(
        'TPIX DEX is not deployed yet.',
        'ยังไม่ได้ติดตั้ง TPIX DEX บนเชน',
      );
    }
    return cfg;
  }

  // ── RPC ──

  /// RPC จากทะเบียนบนเซิร์ฟเวอร์มาก่อน แล้วตามด้วยตัวหลัก/ตัวสำรองในแอป
  /// สลับเฉพาะเมื่อส่งไม่ถึง (403/5xx/หมดเวลา) — revert จากสัญญาไม่สลับ
  Future<T> _withClient<T>(Future<T> Function(Web3Client client) fn) =>
      RpcPool.forChain(ChainConfig.tpix)
          .withClient(fn, preferred: _config.rpc);

  DeployedContract _erc20At(String address) =>
      DeployedContract(_erc20, EthereumAddress.fromHex(address));

  DeployedContract _routerAt(String address) =>
      DeployedContract(_routerAbiParsed, EthereumAddress.fromHex(address));

  // ── หน่วยเงิน (ตรรกะเดียวกับ BscSwapService) ──

  static BigInt toWei(double value, int decimals) {
    final prec = math.min(decimals, 12);
    final text = value.toStringAsFixed(prec);
    final parts = text.split('.');
    final whole = BigInt.parse(parts[0]);
    var frac = parts.length > 1 ? parts[1] : '';
    frac = frac.padRight(decimals, '0').substring(0, decimals);
    final fracValue = frac.isEmpty ? BigInt.zero : BigInt.parse(frac);
    return whole * BigInt.from(10).pow(decimals) + fracValue;
  }

  static double fromWei(BigInt wei, int decimals) =>
      wei.toDouble() / math.pow(10, decimals);

  /// แปลงจำนวนที่ผู้ใช้พิมพ์ (ข้อความทศนิยม) เป็นหน่วยเล็กสุดแบบตรงตัว — ไม่ผ่าน double
  ///
  /// double มีความละเอียด ~15–16 หลัก: เหรียญลูกที่ถือกันหลักร้อยล้าน (IDTC 666,666,666.67)
  /// ปัดแล้วเกินยอดจริงได้ → กด "ขาย 100%" แล้วเจอ "ยอดไม่พอ" / ธุรกรรม revert
  /// คืน null เมื่อรูปแบบผิด · ทศนิยมเกิน [decimals] ตำแหน่งถูกตัดทิ้ง (ปัดลงเสมอ)
  static BigInt? parseUnits(String text, int decimals) {
    final t = text.trim().replaceAll(',', '');
    if (t.isEmpty || t == '.' || !RegExp(r'^\d*\.?\d*$').hasMatch(t)) {
      return null;
    }
    final parts = t.split('.');
    final whole = parts[0].isEmpty ? BigInt.zero : BigInt.parse(parts[0]);
    var frac = parts.length > 1 ? parts[1] : '';
    if (frac.length > decimals) frac = frac.substring(0, decimals);
    frac = frac.padRight(decimals, '0');
    final fracValue = frac.isEmpty ? BigInt.zero : BigInt.parse(frac);
    return whole * BigInt.from(10).pow(decimals) + fracValue;
  }

  /// หน่วยเล็กสุด → ข้อความทศนิยมเต็มความละเอียด (ตัดศูนย์ท้าย) — ใช้เติมช่องจำนวนแบบตรงตัว
  static String formatUnits(BigInt wei, int decimals) {
    if (wei <= BigInt.zero) return '0';
    if (decimals == 0) return wei.toString();
    final s = wei.toString().padLeft(decimals + 1, '0');
    final whole = s.substring(0, s.length - decimals);
    final frac =
        s.substring(s.length - decimals).replaceFirst(RegExp(r'0+$'), '');
    return frac.isEmpty ? whole : '$whole.$frac';
  }

  /// native TPIX ในฐานข้อมูลคือ 0x0 — ตรงนี้ถือว่าเป็น native ทุกกรณีที่ตรงกัน
  static bool isNative(String address) {
    final a = address.toLowerCase();
    return a.isEmpty ||
        a == nativeAddress ||
        a == '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
  }

  // ── คณิตของพูล (UniV2) — ฟังก์ชันล้วน ทดสอบได้โดยไม่แตะเชน ──

  /// UniV2 getAmountOut: หักค่าธรรมเนียม 0.3% จากฝั่งที่จ่ายเข้าแล้วคิด x·y=k
  static BigInt amountOutFor(BigInt amountIn, BigInt reserveIn, BigInt reserveOut) {
    if (amountIn <= BigInt.zero ||
        reserveIn <= BigInt.zero ||
        reserveOut <= BigInt.zero) {
      return BigInt.zero;
    }
    final inWithFee = amountIn * BigInt.from(997);
    return (inWithFee * reserveOut) ~/
        (reserveIn * BigInt.from(1000) + inWithFee);
  }

  /// price impact (%) แบบเดียวกับเว็บ (useTpixDex.js): 1 − (ราคาที่ได้จริง ÷ ราคากลางพูล)
  ///
  /// ราคากลาง = reserveOut/reserveIn · ราคาที่ได้ = amountOut/amountIn (หน่วย wei ทั้งคู่)
  /// ค่านี้รวมค่าธรรมเนียม 0.3% ของพูลไว้ด้วย — ต้องคิดแบบนี้ให้ตรงกับเว็บ
  /// ไม่งั้นไม้ที่ 9.8% ในแอปจะกลายเป็น 10.1% บนเว็บ
  static double priceImpactPct({
    required BigInt amountIn,
    required BigInt amountOut,
    required BigInt reserveIn,
    required BigInt reserveOut,
  }) {
    if (amountIn <= BigInt.zero ||
        reserveIn <= BigInt.zero ||
        reserveOut <= BigInt.zero) {
      return 0;
    }
    final spot = reserveOut.toDouble() / reserveIn.toDouble();
    final eff = amountOut.toDouble() / amountIn.toDouble();
    if (spot <= 0) return 0;
    return math.max(0, (1 - eff / spot) * 100).toDouble();
  }

  /// จ่ายเข้าได้มากสุดเท่าไร (wei) โดย price impact ไม่เกิน [limitPct]
  ///
  /// แก้สมการจากสูตรข้างบน: impact = 1 − 997·R / (1000·R + 997·x) ≤ L
  ///   → x ≤ R · (997 − 1000·(1−L)) / (997·(1−L))
  /// เพดาน 10% → x ≤ ~10.81% ของ reserve ฝั่งจ่ายเข้า
  /// หักเผื่อ [safetyBps] (0.5%) — พูลอาจขยับเล็กน้อยระหว่างผู้ใช้กดเติมกับกดส่ง
  static BigInt maxAmountInForImpact({
    required BigInt reserveIn,
    double limitPct = kTpixMaxPriceImpactPct,
    int safetyBps = 50,
  }) {
    if (reserveIn <= BigInt.zero || limitPct <= 0) return BigInt.zero;
    // คิดเป็นเศษส่วนจำนวนเต็มทั้งหมด — ไม่ให้ double ปัดเกินเพดานไปเอง
    final limitBps = (limitPct * 100).floor(); // 10% = 1000 bps
    final keepBps = BigInt.from(10000 - limitBps); // (1 − L) × 10000
    final numer = BigInt.from(997 * 10000) - BigInt.from(1000) * keepBps;
    if (numer <= BigInt.zero) return BigInt.zero;
    final denom = BigInt.from(997) * keepBps;
    final raw = reserveIn * numer ~/ denom;
    return raw * BigInt.from(10000 - safetyBps) ~/ BigInt.from(10000);
  }

  // ── โทเคน ──

  /// โทเคนที่พร้อมใช้เทรด — decimals ยืนยันกับเชนก่อนเสมอ
  /// (ค่า decimals จาก API เป็นแค่ค่าตั้งต้น ถ้าคลาดเคลื่อนยอดจะเพี้ยนทั้งไม้)
  Future<VerifiedToken> verifyToken({
    required String symbol,
    required String address,
    required int fallbackDecimals,
  }) async {
    if (isNative(address)) {
      return VerifiedToken(
        symbol: symbol,
        address: nativeAddress,
        decimals: 18,
        native: true,
      );
    }

    final key = address.toLowerCase();
    final cached = _decimalsCache[key];
    if (cached != null) {
      return VerifiedToken(
        symbol: symbol,
        address: address,
        decimals: cached,
        native: false,
      );
    }

    try {
      final contract = _erc20At(address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('decimals'),
            params: const [],
          ));
      final decimals = (result.first as BigInt).toInt();
      if (decimals < 0 || decimals > 36) {
        throw const SwapException(
            'Token is not tradable.', 'เหรียญนี้เทรดไม่ได้');
      }
      _decimalsCache[key] = decimals;
      return VerifiedToken(
        symbol: symbol,
        address: address,
        decimals: decimals,
        native: false,
      );
    } on SwapException {
      rethrow;
    } catch (e) {
      // เชนติดต่อไม่ได้ ≠ เหรียญมีปัญหา — บอกผู้ใช้ให้ตรงสาเหตุ
      if (RpcFailover.shouldFailover(e)) throw kTpixChainUnreachable;
      // อ่านเชนไม่ได้ = ไม่ยอมให้เทรดด้วยค่าที่เดา (fail-closed เหมือนฝั่ง BSC)
      throw const SwapException(
        'Could not verify the token on TPIX Chain.',
        'ตรวจสอบเหรียญบนเชน TPIX ไม่สำเร็จ',
      );
    }
  }

  /// ยอดคงเหลือบนเชน TPIX — **คืน null เมื่ออ่านไม่ได้** (ห้ามแปลเป็น 0)
  ///
  /// เดิมคืน 0 ทุกครั้งที่ RPC สะดุด → ผู้ใช้เห็นยอดหายเป็นศูนย์ทั้งที่เงินยังอยู่
  /// ผู้เรียกต้องคงค่าที่อ่านได้ล่าสุดไว้แทน
  Future<double?> getBalance({
    required String address,
    required String walletAddress,
    int decimals = 18,
  }) async {
    final wei =
        await getBalanceWei(address: address, walletAddress: walletAddress);
    if (wei == null) return null;
    final dec = isNative(address)
        ? 18
        : (_decimalsCache[address.toLowerCase()] ?? decimals);
    return fromWei(wei, dec);
  }

  /// ยอดคงเหลือเป็นหน่วยเล็กสุด (ตรงตัว) — null เมื่ออ่านไม่ได้
  Future<BigInt?> getBalanceWei({
    required String address,
    required String walletAddress,
  }) async {
    try {
      final owner = EthereumAddress.fromHex(walletAddress);
      if (isNative(address)) {
        final amount = await _withClient((client) => client.getBalance(owner));
        return amount.getInWei;
      }
      final contract = _erc20At(address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('balanceOf'),
            params: [owner],
          ));
      return result.first as BigInt;
    } catch (e) {
      debugPrint('TPIX DEX getBalance failed: ${e.runtimeType}');
      return null;
    }
  }

  // ── พูล ──

  /// reserve ของพูล tokenIn/tokenOut เรียงตามทิศที่ถาม — null = ไม่มีพูลหรืออ่านไม่ได้
  Future<({BigInt reserveIn, BigInt reserveOut})?> _reserves(
      TpixDexConfig cfg, String tokenIn, String tokenOut) async {
    final factory = cfg.factory;
    if (factory == null) return null;
    final a = tokenIn.toLowerCase();
    final b = tokenOut.toLowerCase();
    final key = a.compareTo(b) < 0 ? '$a|$b' : '$b|$a';

    try {
      var info = _pairCache[key];
      if (info == null) {
        final fc = DeployedContract(
            _factoryAbiParsed, EthereumAddress.fromHex(factory));
        final pairRes = await _withClient((client) => client.call(
              contract: fc,
              function: fc.function('getPair'),
              params: [
                EthereumAddress.fromHex(tokenIn),
                EthereumAddress.fromHex(tokenOut),
              ],
            ));
        final pair = (pairRes.first as EthereumAddress).hex;
        if (isNative(pair)) return null; // ยังไม่มีพูลของคู่นี้
        final pc =
            DeployedContract(_pairAbiParsed, EthereumAddress.fromHex(pair));
        final t0 = await _withClient((client) => client.call(
              contract: pc,
              function: pc.function('token0'),
              params: const [],
            ));
        info = (
          pair: pair,
          token0: (t0.first as EthereumAddress).hex.toLowerCase(),
        );
        _pairCache[key] = info;
      }

      final pc =
          DeployedContract(_pairAbiParsed, EthereumAddress.fromHex(info.pair));
      final res = await _withClient((client) => client.call(
            contract: pc,
            function: pc.function('getReserves'),
            params: const [],
          ));
      final r0 = res[0] as BigInt;
      final r1 = res[1] as BigInt;
      final inIs0 = info.token0 == a;
      return (reserveIn: inIs0 ? r0 : r1, reserveOut: inIs0 ? r1 : r0);
    } catch (e) {
      debugPrint('TPIX DEX reserves failed: ${e.runtimeType}');
      return null;
    }
  }

  /// ราคา TPIX เป็น USDT จาก reserve ของพูล WTPIX/USDT — null = อ่านไม่ได้/ไม่มีพูล
  /// ใช้เป็นตัวสำรองเมื่อ /tpix/price ของเซิร์ฟเวอร์ยังไม่ตอบ
  Future<double?> tpixUsdtPoolPrice() async {
    final cfg = await loadConfig();
    final wtpix = cfg.wtpix;
    final usdt = cfg.usdt;
    if (!cfg.ready || wtpix == null || usdt == null) return null;
    try {
      final usdtTok =
          await verifyToken(symbol: 'USDT', address: usdt, fallbackDecimals: 6);
      final r = await _reserves(cfg, wtpix, usdt);
      if (r == null || r.reserveIn <= BigInt.zero) return null;
      final tpix = fromWei(r.reserveIn, 18);
      final quote = fromWei(r.reserveOut, usdtTok.decimals);
      return tpix > 0 ? quote / tpix : null;
    } catch (_) {
      return null;
    }
  }

  // ── Quote ──

  List<EthereumAddress> _directPath(
      TpixDexConfig cfg, VerifiedToken fromToken, VerifiedToken toToken) {
    final fromAddr = fromToken.native ? cfg.wtpix! : fromToken.address;
    final toAddr = toToken.native ? cfg.wtpix! : toToken.address;
    if (fromAddr.toLowerCase() == toAddr.toLowerCase()) {
      throw const SwapException(
          'Choose two different tokens.', 'เลือกเหรียญคนละตัว');
    }
    return [EthereumAddress.fromHex(fromAddr), EthereumAddress.fromHex(toAddr)];
  }

  List<EthereumAddress> _viaWtpix(
          TpixDexConfig cfg, List<EthereumAddress> direct) =>
      [direct.first, EthereumAddress.fromHex(cfg.wtpix!), direct.last];

  /// ขอ quote จริงจาก router — จ่าย [amount] ของ fromToken (exact-in)
  ///
  /// [amountInWei] (ถ้าให้มา) ใช้แทน [amount] แบบตรงตัว — ควรส่งเสมอเมื่อจำนวนมาจาก
  /// ข้อความที่ผู้ใช้พิมพ์ ([parseUnits]) หรือยอดคงเหลือบนเชน ไม่ให้ double ปัดเกินยอดจริง
  Future<TpixDexQuote> getQuote({
    required VerifiedToken fromToken,
    required VerifiedToken toToken,
    required double amount,
    double slippage = 0.5,
    BigInt? amountInWei,
  }) async {
    if (amountInWei == null && amount <= 0) {
      throw const SwapException('Enter an amount.', 'กรุณาระบุจำนวน');
    }
    checkSlippage(slippage);

    final cfg = await _requireReady();

    amountInWei ??= toWei(amount, fromToken.decimals);
    if (amountInWei <= BigInt.zero) {
      throw const SwapException(
          'Amount is too small.', 'จำนวนน้อยเกินไป');
    }

    return _quoteExactIn(cfg, fromToken, toToken, amountInWei, slippage);
  }

  /// quote แบบ "อยากได้ [amountOut] ของ toToken" — ใช้ตอนกดซื้อ TPIX ตามจำนวนที่กรอก
  ///
  /// เดิมแปลงจำนวนเป็นยอด USDT ด้วยราคาจาก ticker ซึ่งโหลดแข่งกับอย่างอื่นตอนเปิดแอป
  /// ราคายังเป็น 0 อยู่ = กดซื้อไม่ได้นานถึง 30 วิ — ตอนนี้ให้ router (getAmountsIn)
  /// บอกเองว่าต้องจ่ายเท่าไร แล้ว quote ยอดนั้นซ้ำด้วย getAmountsOut ให้ได้ตัวเลขที่จะส่งจริง
  Future<TpixDexQuote> getQuoteForOutput({
    required VerifiedToken fromToken,
    required VerifiedToken toToken,
    required double amountOut,
    double slippage = 0.5,
    BigInt? amountOutWei,
  }) async {
    if (amountOutWei == null && amountOut <= 0) {
      throw const SwapException('Enter an amount.', 'กรุณาระบุจำนวน');
    }
    checkSlippage(slippage);

    final cfg = await _requireReady();
    final outWei = amountOutWei ?? toWei(amountOut, toToken.decimals);
    if (outWei <= BigInt.zero) {
      throw const SwapException(
          'Amount is too small.', 'จำนวนน้อยเกินไป');
    }

    final direct = _directPath(cfg, fromToken, toToken);
    var path = direct;
    var amounts = await _routerAmounts('getAmountsIn', cfg.router!, outWei, path);
    if (amounts == null && !fromToken.native && !toToken.native) {
      path = _viaWtpix(cfg, direct);
      amounts =
          await _routerAmounts('getAmountsIn', cfg.router!, outWei, path);
    }

    if (amounts == null || amounts.isEmpty || amounts.first <= BigInt.zero) {
      // getAmountsIn revert = ขอเกินที่พูลมี — บอกจำนวนที่สั่งได้จริงแทนข้อความลอย ๆ
      final r = await _reserves(cfg, direct.first.hex, direct.last.hex);
      if (r != null) {
        final maxOut = fromWei(
            amountOutFor(maxAmountInForImpact(reserveIn: r.reserveIn),
                r.reserveIn, r.reserveOut),
            toToken.decimals);
        if (maxOut > 0) {
          final s = formatMaxAmount(maxOut);
          throw SwapException(
            'This order is larger than the pool can fill. Max ≈ $s ${toToken.symbol} per order.',
            'ไม้นี้ใหญ่เกินกว่าที่พูลรับได้ — สั่งได้สูงสุดราว $s ${toToken.symbol} ต่อไม้',
          );
        }
      }
      throw const SwapException(
        'No liquidity available for this pair yet.',
        'คู่นี้ยังไม่มีสภาพคล่องบนเชน TPIX',
      );
    }

    return _quoteExactIn(cfg, fromToken, toToken, amounts.first, slippage,
        path: path);
  }

  Future<TpixDexQuote> _quoteExactIn(
    TpixDexConfig cfg,
    VerifiedToken fromToken,
    VerifiedToken toToken,
    BigInt amountInWei,
    double slippage, {
    List<EthereumAddress>? path,
  }) async {
    final router = cfg.router!;
    final direct = _directPath(cfg, fromToken, toToken);

    var usedPath = path ?? direct;
    var amounts = await _routerAmounts('getAmountsOut', router, amountInWei, usedPath);

    // ไม่มีคู่ตรง → ลองวิ่งผ่าน WTPIX (เฉพาะ token ↔ token)
    if (amounts == null &&
        path == null &&
        !fromToken.native &&
        !toToken.native) {
      usedPath = _viaWtpix(cfg, direct);
      amounts = await _routerAmounts('getAmountsOut', router, amountInWei, usedPath);
    }

    if (amounts == null || amounts.isEmpty || amounts.last <= BigInt.zero) {
      throw const SwapException(
        'No liquidity available for this pair yet.',
        'คู่นี้ยังไม่มีสภาพคล่องบนเชน TPIX',
      );
    }

    final rawOut = amounts.last;
    final netOutput = fromWei(rawOut, toToken.decimals);
    final minReceived =
        math.max(netOutput * (1 - slippage / 100), 0).toDouble();

    // price impact จาก reserve ของพูล — สูตรเดียวกับเว็บ ใช้ได้เมื่อวิ่งฮอปเดียว
    BigInt? reserveIn;
    BigInt? reserveOut;
    double? priceImpact;
    if (usedPath.length == 2) {
      final r = await _reserves(cfg, usedPath[0].hex, usedPath[1].hex);
      if (r != null) {
        reserveIn = r.reserveIn;
        reserveOut = r.reserveOut;
        priceImpact = priceImpactPct(
          amountIn: amountInWei,
          amountOut: rawOut,
          reserveIn: r.reserveIn,
          reserveOut: r.reserveOut,
        );
      }
    }

    // อ่าน reserve ไม่ได้ / วิ่ง 2 ฮอป → วัดด้วย quote ก้อนจิ๋ว (1/1000 ของไม้) แทน
    // ห้ามปล่อยเป็น 0 เพราะ 0 = ผ่านด่าน 10% ทุกไม้ ไม่ว่าจะใหญ่แค่ไหน
    if (priceImpact == null) {
      priceImpact = 0;
      final probeWei = amountInWei ~/ BigInt.from(1000);
      if (probeWei > BigInt.zero) {
        final probe =
            await _routerAmounts('getAmountsOut', router, probeWei, usedPath);
        if (probe != null && probe.isNotEmpty && probe.last > BigInt.zero) {
          final spot = probe.last.toDouble() / probeWei.toDouble();
          final effective = rawOut.toDouble() / amountInWei.toDouble();
          if (spot > 0) {
            priceImpact = math.max(0, (1 - effective / spot) * 100).toDouble();
          }
        }
      }
    }

    return TpixDexQuote(
      fromToken: fromToken,
      toToken: toToken,
      amountIn: fromWei(amountInWei, fromToken.decimals),
      netOutput: netOutput,
      minReceived: minReceived,
      slippage: slippage,
      priceImpact: double.parse(priceImpact.toStringAsFixed(4)),
      amountInWei: amountInWei,
      rawAmountOut: rawOut,
      path: usedPath,
      router: router,
      reserveIn: reserveIn,
      reserveOut: reserveOut,
    );
  }

  /// เรียก getAmountsOut/getAmountsIn ของ router
  ///
  /// revert (ไม่มีพูล/สภาพคล่องไม่พอ) = null · ส่งไม่ถึงเชน = โยน [kTpixChainUnreachable]
  /// แยกสองกรณีนี้ออกจากกัน — เดิมเหมาเป็น "ไม่มีสภาพคล่อง" ทั้งที่จริงคือ RPC ล่ม
  Future<List<BigInt>?> _routerAmounts(String fn, String router,
      BigInt amount, List<EthereumAddress> path) async {
    try {
      final contract = _routerAt(router);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function(fn),
            params: [amount, path],
          ));
      return (result.first as List).cast<BigInt>();
    } on RPCError catch (e) {
      debugPrint('TPIX DEX $fn reverted: ${e.errorCode}');
      return null;
    } catch (e) {
      debugPrint('TPIX DEX $fn failed: ${e.runtimeType}');
      if (RpcFailover.shouldFailover(e)) throw kTpixChainUnreachable;
      return null;
    }
  }

  /// จำนวนสูงสุดสำหรับแสดงผล — ปัดลงเสมอ (ปัดขึ้นแล้วกดตามจะเกินเพดาน)
  static String formatMaxAmount(double v) {
    final dp = v >= 1000 ? 2 : (v >= 1 ? 4 : 6);
    final factor = math.pow(10, dp).toDouble();
    final floored = (v * factor).floorToDouble() / factor;
    var s = floored.toStringAsFixed(dp);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }

  /// เพดาน slippage — ค่าจากการตั้งค่า (ซิงก์จากเซิร์ฟเวอร์/เก็บในเครื่อง) อาจผิดรูปหรือถูกแก้
  /// slippage 99% = minOut แทบเป็น 0 → โดน sandwich ได้ทั้งไม้ จึงปฏิเสธก่อนถึงกระเป๋า
  static const double kMaxSlippagePct = 50;

  static void checkSlippage(double slippage) {
    if (!slippage.isFinite || slippage <= 0 || slippage > kMaxSlippagePct) {
      throw const SwapException(
        'Slippage must be between 0.01% and 50%.',
        'slippage ต้องอยู่ระหว่าง 0.01% ถึง 50%',
      );
    }
  }

  // ── Allowance / Approve ──

  Future<bool> hasAllowance(
      VerifiedToken token, String owner, BigInt wei) async {
    if (token.native) return true;
    final cfg = await _requireReady();
    try {
      final contract = _erc20At(token.address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('allowance'),
            params: [
              EthereumAddress.fromHex(owner),
              EthereumAddress.fromHex(cfg.router!),
            ],
          ));
      return (result.first as BigInt) >= wei;
    } catch (e) {
      // ส่งไม่ถึงเชน = อย่าเพิ่งขอ approve (ส่งธุรกรรมก็ไม่ถึงเหมือนกัน) บอกผู้ใช้ตรง ๆ
      if (RpcFailover.shouldFailover(e)) throw kTpixChainUnreachable;
      return false; // อ่านไม่ได้ = บังคับ approve (ปลอดภัยกว่าเดาว่าพอ)
    }
  }

  /// Calldata approve router **เท่ากับยอดของไม้นี้พอดี** (ไม่ใช่ไม่จำกัด)
  ///
  /// เดิม approve 2^256−1 ทิ้งไว้ตลอด — ถ้า router/กุญแจฝั่งไหนมีปัญหาในวันหน้า
  /// ยอดทั้งกระเป๋าของเหรียญนั้นเปิดให้ดึงได้ทันที ยอมเซ็นเพิ่มทีละไม้ปลอดภัยกว่า
  Future<Uint8List> approveCalldata(String tokenAddress, BigInt amount) async {
    if (amount <= BigInt.zero) {
      throw const SwapException('Enter an amount.', 'กรุณาระบุจำนวน');
    }
    final cfg = await _requireReady();
    final contract = _erc20At(tokenAddress);
    return contract.function('approve').encodeCall([
      EthereumAddress.fromHex(cfg.router!),
      amount,
    ]);
  }

  // ── Execute ──

  /// ส่ง swap จริงตาม [quote]
  ///
  /// ไม่มีขั้นเก็บค่าธรรมเนียมแยกเหมือนฝั่ง BSC เพราะพูลกิน 0.3% ในตัวอยู่แล้ว
  /// (ฝั่งเซิร์ฟเวอร์รับ fee_amount = 0 สำหรับเชน 4289 — `fee_model: in_pool`)
  Future<TpixDexSwapResult> executeMarketSwap({
    required TpixDexQuote quote,
    required String walletAddress,
    required DexTxSender sendTx,
  }) async {
    // ด่านสุดท้ายก่อนแตะเงิน — กติกาเดียวกับเว็บ ไม่ว่าหน้าจอจะเช็กมาแล้วหรือยัง
    if (quote.exceedsImpactLimit) {
      throw SwapException(
        'This order moves the pool price by ${quote.priceImpact.toStringAsFixed(1)}% (limit ${kTpixMaxPriceImpactPct.toStringAsFixed(0)}%).',
        'คำสั่งนี้ดันราคาพูลไป ${quote.priceImpact.toStringAsFixed(1)}% (เพดาน ${kTpixMaxPriceImpactPct.toStringAsFixed(0)}%)',
      );
    }

    final slipBps =
        BigInt.from(math.max(0, ((1 - quote.slippage / 100) * 10000).floor()));
    final minOut = (quote.rawAmountOut * slipBps) ~/ BigInt.from(10000);
    if (minOut <= BigInt.zero) {
      throw const SwapException(
        'Slippage too high — please adjust and retry.',
        'ค่า slippage สูงเกินไป ปรับแล้วลองใหม่',
      );
    }

    final deadline = BigInt.from(
        DateTime.now().millisecondsSinceEpoch ~/ 1000 + 1200); // 20 นาที
    final recipient = EthereumAddress.fromHex(walletAddress);
    final contract = _routerAt(quote.router);

    Uint8List data;
    BigInt? txValue;
    if (quote.fromToken.native) {
      data = contract.function('swapExactETHForTokens').encodeCall([
        minOut,
        quote.path,
        recipient,
        deadline,
      ]);
      txValue = quote.amountInWei;
    } else if (quote.toToken.native) {
      data = contract.function('swapExactTokensForETH').encodeCall([
        quote.amountInWei,
        minOut,
        quote.path,
        recipient,
        deadline,
      ]);
    } else {
      data = contract.function('swapExactTokensForTokens').encodeCall([
        quote.amountInWei,
        minOut,
        quote.path,
        recipient,
        deadline,
      ]);
    }

    final txHash = await sendTx(to: quote.router, data: data, value: txValue);
    if (txHash == null || txHash.isEmpty) {
      throw const SwapException(
        'Transaction rejected or failed to send.',
        'ธุรกรรมถูกปฏิเสธหรือส่งไม่สำเร็จ',
      );
    }

    final receipt = await _waitReceipt(txHash, attempts: 40);
    if (receipt != null && receipt.status == false) {
      throw const SwapException(
        'Swap failed on-chain. Try a smaller amount or higher slippage.',
        'สวอปล้มเหลวบนเชน ลองจำนวนน้อยลงหรือเพิ่ม slippage',
      );
    }
    final confirmed = receipt?.status == true;

    // บันทึกฝั่งเซิร์ฟเวอร์ (best-effort) — ให้ประวัติ/ป้ายบนกราฟเห็นไม้นี้
    try {
      await ApiService().recordSwapExecution(
        fromToken:
            quote.fromToken.native ? nativeAddress : quote.fromToken.address,
        toToken: quote.toToken.native ? nativeAddress : quote.toToken.address,
        fromAmount: quote.amountIn,
        toAmount: quote.netOutput,
        feeAmount: 0, // ค่าธรรมเนียมอยู่ในพูล
        txHash: txHash,
        chainId: _config.chainId,
        walletAddress: walletAddress,
      );
    } catch (e) {
      debugPrint('recordSwapExecution (TPIX DEX) failed: ${e.runtimeType}');
    }

    return TpixDexSwapResult(
      txHash: txHash,
      explorerUrl: '${ChainConfig.tpix.explorerUrl}/tx/$txHash',
      confirmed: confirmed,
    );
  }

  Future<bool> waitConfirmed(String txHash, {int attempts = 40}) async {
    final receipt = await _waitReceipt(txHash, attempts: attempts);
    return receipt?.status == true;
  }

  Future<TransactionReceipt?> _waitReceipt(String txHash,
      {int attempts = 40}) async {
    for (var i = 0; i < attempts; i++) {
      try {
        final receipt = await _withClient(
            (client) => client.getTransactionReceipt(txHash));
        if (receipt != null) return receipt;
      } catch (_) {
        // RPC สะดุดชั่วคราว — รอบถัดไปลองใหม่
      }
      // เชน TPIX ออกบล็อกทุก 2 วิ — ถามถี่กว่าฝั่ง BSC ได้
      await Future.delayed(const Duration(seconds: 2));
    }
    return null;
  }
}
