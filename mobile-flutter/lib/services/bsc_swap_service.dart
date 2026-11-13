/// TPIX TRADE — BSC Swap Service (mobile)
/// Engine สำหรับเทรดจริงบน BSC ผ่าน PancakeSwap V2 — mirror มาจาก
/// useSwap.js ฝั่งเว็บ ให้พฤติกรรมสอดคล้องกันที่สุด:
///
///  - ราคา/ยอด/allowance อ่านจาก BSC RPC ตรง (ไม่ขึ้นกับเชนของ wallet)
///  - ค่าธรรมเนียมแพลตฟอร์มหักจากฝั่ง INPUT แล้วกันไว้ (fee reserve):
///    swap เฉพาะ (amount - fee) → โอน fee แยกหลัง swap สำเร็จ ไม่มีทางเกินยอด
///  - minOut คิดจาก slippage เท่านั้น (fee ไม่ไปกดยอดที่ผู้ใช้ได้รับ)
///  - FAIL-CLOSED: ไม่รู้ที่อยู่ fee collector = ไม่ swap (กัน fee หายเงียบ)
///  - FAIL-CLOSED: `/swap/quote` ไม่ตอบ / ไม่มี fee_rate = ไม่มีราคา = ไม่ส่งธุรกรรม
///    (ไม่มีอัตราปริยายให้เดา — ด่านปิดเชน/ปิดคู่ฉุกเฉินของแอดมินต้องมีผลกับแอปด้วย)
///  - price impact วัดจากไม้จิ๋วบนเส้นทางเดียวกัน (สูตรเดียวกับเว็บ) เกิน 10% / วัดไม่ได้ = ไม่ส่ง
///  - จำนวนคิดเป็นหน่วยเล็กสุด (BigInt) ตัดทศนิยมทิ้ง ไม่ปัดขึ้น และตัดให้พอดียอดจริงบนเชนก่อนส่ง
///  - บันทึกไม้เข้าคิวในเครื่องทันทีที่รู้ tx hash ([PendingSwapRecords]) — ส่งซ้ำจนกว่าเซิร์ฟเวอร์รับ
///  - ตรวจ symbol()/decimals() ของ token กับ on-chain ก่อนเทรดทุกครั้ง
///
/// การเซ็น/ส่งธุรกรรมเป็นหน้าที่ของ caller (WalletProvider) ผ่าน [BscTxSender]
/// — service นี้ไม่แตะ private key เด็ดขาด
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:web3dart/web3dart.dart';

import '../models/bsc_trade_tokens.dart';
import '../models/chain_config.dart';
import 'api_service.dart';
import 'pending_swap_records.dart';

/// Error ที่มีข้อความพร้อมแสดงต่อผู้ใช้ทั้ง 2 ภาษา
class SwapException implements Exception {
  final String en;
  final String th;
  const SwapException(this.en, this.th);

  String message(bool isThai) => isThai ? th : en;

  @override
  String toString() => en;
}

/// เพดาน price impact ของไม้บน BSC (%) — **ต้องเท่ากับเว็บ** (useSwap.js `BSC_PRICE_IMPACT_BLOCK_PCT`)
const double kBscMaxPriceImpactPct = 10;

/// แอดมินปิดเชน/เหรียญ/คู่นี้ (404 `INVALID_CHAIN` / `INVALID_TOKEN` / `PAIR_DISABLED`)
const SwapException kSwapPairDisabled = SwapException(
  "This pair isn't open for trading right now.",
  'คู่นี้ปิดการเทรดอยู่ในขณะนี้',
);

/// ขออัตราค่าธรรมเนียมจากเซิร์ฟเวอร์ไม่ได้ — ไม่รู้อัตราจริง = ไม่ส่งธุรกรรม
const SwapException kSwapFeeConfigUnavailable = SwapException(
  "Couldn't confirm the platform fee — please try again in a moment.",
  'ตรวจค่าธรรมเนียมแพลตฟอร์มไม่สำเร็จ — ลองใหม่อีกครั้งในอีกสักครู่',
);

/// ยอดที่จะจ่ายเกินยอดจริงบนเชน
const SwapException kSwapInsufficientBalance = SwapException(
  'Insufficient balance for this order.',
  'ยอดคงเหลือไม่พอสำหรับรายการนี้',
);

/// วัด price impact ไม่ได้ และไม่มีราคาตลาดให้เทียบ — ไม่รู้ว่าพูลรับไม้นี้ได้ไหม
const SwapException kSwapImpactUnknown = SwapException(
  "Couldn't check the price impact — please try again.",
  'ตรวจผลกระทบต่อราคาไม่ได้ — ลองใหม่อีกครั้ง',
);

/// ไม้นี้ดันราคาพูลเกินเพดาน
SwapException swapImpactTooHigh(double impact) => SwapException(
      'This order moves the pool price by ${impact.toStringAsFixed(2)}% (limit ${kBscMaxPriceImpactPct.toStringAsFixed(0)}%). Try a smaller amount.',
      'คำสั่งนี้ดันราคาพูลไป ${impact.toStringAsFixed(2)}% (เพดาน ${kBscMaxPriceImpactPct.toStringAsFixed(0)}%) ลองลดจำนวนลง',
    );

/// ข้อมูลประกอบบันทึกไม้จากกระดานเทรด — ให้ประวัติโชว์ชื่อคู่/ฝั่ง/ราคา แทนที่อยู่สัญญายาวๆ
/// (หน้า Swap ไม่มีแนวคิด "คู่/ฝั่ง" ไม่ต้องส่ง — เซิร์ฟเวอร์อนุมานให้เองตอนอ่าน)
class SwapRecordMeta {
  /// ชื่อคู่ เช่น `BTC-USDT` (ส่งให้เซิร์ฟเวอร์เป็น `BTC/USDT`)
  final String? pair;

  /// `buy` | `sell`
  final String? side;

  /// ราคาตลาดตอนกด (quote ต่อ 1 base) — ใช้เมื่อคิดราคาที่ได้จริงจากยอดไม่ได้
  final double? price;

  const SwapRecordMeta({this.pair, this.side, this.price});
}

/// Token ที่ผ่านการตรวจกับ on-chain แล้ว (decimals เป็นค่าจริงจากเชน)
class VerifiedToken {
  final String symbol;
  final String address;
  final int decimals;
  final bool native;

  const VerifiedToken({
    required this.symbol,
    required this.address,
    required this.decimals,
    required this.native,
  });
}

/// ผล quote จาก router จริง — ตัวเลข wei เก็บไว้ใช้ตอน execute ตรงๆ
class BscSwapQuote {
  final VerifiedToken fromToken;
  final VerifiedToken toToken;
  final double amountIn; // ยอดรวมที่ผู้ใช้จ่าย (swap + fee)
  final double swapInput; // ยอดที่วิ่งเข้า router จริง
  final double feeAmount; // fee เป็นหน่วยของ input token
  final double feeRate; // %
  final double slippage; // %
  final double netOutput; // ที่ผู้ใช้จะได้รับ (ประมาณ)
  final double minReceived; // ขั้นต่ำหลัง slippage
  final BigInt amountInSwapWei;
  final BigInt feeWei;
  final BigInt rawAmountOut;
  final List<EthereumAddress> path;

  /// ไม้นี้ดันราคาพูลไปเท่าไร (%) — **null = วัดไม่ได้** (ไม่ใช่ 0)
  /// เทียบอัตราของไม้จริงกับไม้จิ๋ว (1/1000) บนเส้นทางเดียวกัน สูตรเดียวกับเว็บ
  final double? priceImpact;

  const BscSwapQuote({
    required this.fromToken,
    required this.toToken,
    required this.amountIn,
    required this.swapInput,
    required this.feeAmount,
    required this.feeRate,
    required this.slippage,
    required this.netOutput,
    required this.minReceived,
    required this.amountInSwapWei,
    required this.feeWei,
    required this.rawAmountOut,
    required this.path,
    this.priceImpact,
  });

  /// ยอดรวมที่ผู้ใช้จ่ายจริง (swap + fee) เป็นหน่วยเล็กสุด
  BigInt get grossWei => amountInSwapWei + feeWei;

  /// minOut ที่จะส่งเข้า router ถ้าใช้ quote นี้ — สูตรเดียวกับ executeMarketSwap
  /// (หน้าจอเก็บค่านี้ของ quote ที่ผู้ใช้เห็น ไว้เป็นพื้นขั้นต่ำตอนส่งจริง)
  BigInt get minOutWei => BscSwapService.minOutFor(rawAmountOut, slippage);

  /// ไม้นี้ต้องถูกบล็อกเพราะผลกระทบต่อราคาไหม — ดู [BscSwapService.isPriceImpactBlocked]
  bool isImpactBlocked({bool hasMarketReference = false}) =>
      BscSwapService.isPriceImpactBlocked(priceImpact,
          hasMarketReference: hasMarketReference);
}

/// ผลการ swap ที่ส่งขึ้นเชนแล้ว
class SwapResult {
  final String txHash;
  final String explorerUrl;
  final bool confirmed; // false = ส่งแล้วแต่รอยืนยันนานเกิน (ไม่ใช่ fail)
  final bool feeCollected;

  /// เซิร์ฟเวอร์รับบันทึกไม้นี้แล้ว — false = ค้างอยู่ในคิวในเครื่อง จะส่งใหม่ให้เอง
  final bool recorded;

  /// quote ที่ส่งจริง (อาจถูกตัดให้พอดียอดบนเชน ต่างจากที่ส่งเข้ามาเล็กน้อย)
  final BscSwapQuote quote;

  const SwapResult({
    required this.txHash,
    required this.explorerUrl,
    required this.confirmed,
    required this.feeCollected,
    required this.recorded,
    required this.quote,
  });
}

/// Callback ส่งธุรกรรม — คืน tx hash หรือ null ถ้าผู้ใช้ปฏิเสธ/ส่งไม่ได้
/// (ผู้ implement คือ WalletProvider ซึ่งถือ key/session)
typedef BscTxSender = Future<String?> Function({
  required String to,
  Uint8List? data,
  BigInt? value,
});

/// ABI ขั้นต่ำ — เฉพาะ function ที่ใช้จริง
const String _erc20Abi = '''
[
  {"constant":true,"inputs":[],"name":"symbol","outputs":[{"name":"","type":"string"}],"type":"function"},
  {"constant":true,"inputs":[],"name":"decimals","outputs":[{"name":"","type":"uint8"}],"type":"function"},
  {"constant":true,"inputs":[{"name":"owner","type":"address"}],"name":"balanceOf","outputs":[{"name":"","type":"uint256"}],"type":"function"},
  {"constant":true,"inputs":[{"name":"owner","type":"address"},{"name":"spender","type":"address"}],"name":"allowance","outputs":[{"name":"","type":"uint256"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"spender","type":"address"},{"name":"value","type":"uint256"}],"name":"approve","outputs":[{"name":"","type":"bool"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"to","type":"address"},{"name":"value","type":"uint256"}],"name":"transfer","outputs":[{"name":"","type":"bool"}],"type":"function"}
]
''';

const String _routerAbi = '''
[
  {"constant":true,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"path","type":"address[]"}],"name":"getAmountsOut","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactETHForTokens","outputs":[{"name":"amounts","type":"uint256[]"}],"payable":true,"type":"function"},
  {"constant":false,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactTokensForETH","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactTokensForTokens","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"}
]
''';

class BscSwapService {
  BscSwapService._();
  static final BscSwapService _instance = BscSwapService._();
  factory BscSwapService() => _instance;

  static const int chainId = 56;

  final _erc20 = ContractAbi.fromJson(_erc20Abi, 'ERC20');
  final _router = ContractAbi.fromJson(_routerAbi, 'PancakeRouter');

  // Cache token ที่ตรวจ on-chain แล้ว — address บน mainnet ไม่เปลี่ยนระหว่างรัน
  final Map<String, VerifiedToken> _verifiedCache = {};

  /// RPC ทั้งหมดของ BSC (primary + fallback) จาก ChainConfig กลาง
  List<String> get _rpcUrls =>
      [ChainConfig.bsc.rpcUrl, ...ChainConfig.bsc.fallbackRpcs];

  /// รัน [fn] กับ BSC RPC — ล้มตัวแรกลองตัวถัดไปจนหมด
  Future<T> _withClient<T>(Future<T> Function(Web3Client client) fn) async {
    Object? lastError;
    for (final url in _rpcUrls) {
      final httpClient = http.Client();
      final client = Web3Client(url, httpClient);
      try {
        return await fn(client).timeout(const Duration(seconds: 15));
      } catch (e) {
        lastError = e;
      } finally {
        client.dispose();
      }
    }
    throw lastError ??
        const SwapException('BSC network unavailable.', 'เชื่อมต่อ BSC ไม่ได้');
  }

  DeployedContract _erc20At(String address) =>
      DeployedContract(_erc20, EthereumAddress.fromHex(address));

  DeployedContract get _routerContract =>
      DeployedContract(_router, EthereumAddress.fromHex(kPancakeRouterAddress));

  // ── หน่วยเงิน ──

  /// แปลงจำนวน (double) → wei ตาม decimals — **ตัดทิ้ง ไม่ปัดขึ้น**
  ///
  /// จำกัดทศนิยม 12 หลักกัน float garbage หลักท้าย · `toStringAsFixed` ปัดเข้าหาค่าใกล้สุด
  /// (1.9999999999996 → "2.000000000000") ถ้าผลปัดแล้วมากกว่าค่าจริง ถอยลงหนึ่งหน่วยของหลักสุดท้าย
  /// — ปัดขึ้นแม้ 1 wei ก็ทำให้ยอดที่ส่งเกินยอดจริง แล้วธุรกรรมโอนค่าธรรมเนียม revert หลังสวอปผ่านไปแล้ว
  /// ค่าที่ไม่ใช่จำนวนบวกปกติ (NaN/∞/ใหญ่เกิน 1e21) = 0 (ผู้เรียกปฏิเสธเป็น "จำนวนน้อยเกินไป")
  static BigInt toWei(double value, int decimals) {
    if (!value.isFinite || value <= 0 || value >= 1e21) return BigInt.zero;
    final prec = math.min(decimals, 12);
    final text = value.toStringAsFixed(prec);
    final parts = text.split('.');
    final whole = BigInt.parse(parts[0]);
    var frac = parts.length > 1 ? parts[1] : '';
    frac = frac.padRight(decimals, '0').substring(0, decimals);
    final fracValue = frac.isEmpty ? BigInt.zero : BigInt.parse(frac);
    var wei = whole * BigInt.from(10).pow(decimals) + fracValue;
    if (double.parse(text) > value) {
      wei -= BigInt.from(10).pow(decimals - prec);
    }
    return wei < BigInt.zero ? BigInt.zero : wei;
  }

  /// แปลง wei → double สำหรับแสดงผล
  static double fromWei(BigInt wei, int decimals) =>
      wei.toDouble() / math.pow(10, decimals);

  /// จำนวน → ข้อความทศนิยม [dp] ตำแหน่งแบบ **ตัดทิ้ง ไม่ปัดขึ้น** (ตัดศูนย์ท้ายออก)
  ///
  /// ใช้เติมจำนวนจากปุ่ม 25/50/75/100% — `toStringAsFixed` ปัดเข้าหาค่าใกล้สุด
  /// 100% ของ 1.23456 ที่ 4 ตำแหน่งได้ "1.2346" ซึ่งเกินยอดจริง → สวอปผ่าน (ใช้ 99.7%)
  /// แต่ธุรกรรมโอนค่าธรรมเนียม revert · ค่าที่ไม่ใช่จำนวนบวกปกติ = "0"
  static String floorDecimal(double value, int dp) {
    if (!value.isFinite || value <= 0 || value >= 1e21) return '0';
    var text = value.toStringAsFixed(dp);
    if (double.parse(text) > value) {
      // ปัดขึ้นไปแล้ว — ถอยลงหนึ่งหน่วยของหลักสุดท้าย (คิดด้วย BigInt ไม่ผ่าน float)
      final unit = BigInt.from(10).pow(dp);
      final scaled = BigInt.parse(text.replaceAll('.', '')) - BigInt.one;
      final whole = scaled ~/ unit;
      final frac = (scaled % unit).toString().padLeft(dp, '0');
      text = dp == 0 ? '$whole' : '$whole.$frac';
    }
    if (text.contains('.')) {
      text = text
          .replaceFirst(RegExp(r'0+$'), '')
          .replaceFirst(RegExp(r'\.$'), '');
    }
    return text;
  }

  /// ข้อความทศนิยมที่ผู้ใช้พิมพ์ → หน่วยเล็กสุดแบบตรงตัว (ไม่ผ่าน double)
  ///
  /// คืน null เมื่อรูปแบบผิด · ทศนิยมเกิน [decimals] ตำแหน่งถูกตัดทิ้ง (ไม่ปัดขึ้น)
  /// (กติกาเดียวกับ `toUnits` ของเว็บ และ TpixDexService.parseUnits)
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

  /// wei → ข้อความทศนิยมตรงตัว (ไม่ผ่าน double) — ใช้ส่งจำนวนให้ backend
  static String unitsToDecimal(BigInt wei, int decimals) {
    final negative = wei < BigInt.zero;
    final digits = wei.abs().toString().padLeft(decimals + 1, '0');
    final whole = digits.substring(0, digits.length - decimals);
    final frac = digits
        .substring(digits.length - decimals)
        .replaceFirst(RegExp(r'0+$'), '');
    final text = frac.isEmpty ? whole : '$whole.$frac';
    return negative ? '-$text' : text;
  }

  /// แบ่งยอดที่ผู้ใช้จ่ายเป็น (ค่าธรรมเนียม, ส่วนที่สวอป) ด้วย BigInt ล้วน — สูตรเดียวกับเว็บ
  ///
  /// ค่าธรรมเนียมปัดขึ้น 1 หน่วยเล็กสุด (อัตรา > 0 ต้องไม่ได้ค่าธรรมเนียม 0 เพราะการปัด)
  /// ผลรวมสองส่วนเท่ากับ [grossWei] พอดีเสมอ — ไม่มีทางส่งเกินยอดที่ตั้งใจจ่าย
  static ({BigInt feeWei, BigInt swapWei}) splitPlatformFee(
      BigInt grossWei, double feeRatePct) {
    final rate = feeRatePct.isFinite ? feeRatePct : 0.0;
    final ppm = BigInt.from(math.max(0, (rate * 10000).round()));
    if (ppm == BigInt.zero || grossWei <= BigInt.zero) {
      return (feeWei: BigInt.zero, swapWei: grossWei);
    }
    final million = BigInt.from(1000000);
    final feeWei = (grossWei * ppm + BigInt.from(999999)) ~/ million;
    return (feeWei: feeWei, swapWei: grossWei - feeWei);
  }

  /// minOut จาก slippage เท่านั้น — คิดเป็น basis point ป้องกัน float
  static BigInt minOutFor(BigInt rawAmountOut, double slippagePct) {
    final slipBps = BigInt.from(
        math.max(0, ((1 - slippagePct / 100) * 10000).floor()));
    return (rawAmountOut * slipBps) ~/ BigInt.from(10000);
  }

  /// ผลกระทบต่อราคา (%) จากการเทียบอัตราของไม้จริงกับไม้จิ๋วบนเส้นทางเดียวกัน
  ///
  /// ค่าธรรมเนียม LP ของสองไม้เท่ากัน จึงหักล้างกันเอง เหลือแค่ส่วนที่ไม้นี้ดันราคา
  /// (สูตรเดียวกับ `priceImpactPct` ใน useSwap.js) — **null = คำนวณไม่ได้ ไม่ใช่ 0**
  static double? priceImpactPct({
    required BigInt amountIn,
    required BigInt amountOut,
    required BigInt refIn,
    required BigInt refOut,
  }) {
    if (amountIn <= BigInt.zero ||
        refIn <= BigInt.zero ||
        refOut <= BigInt.zero ||
        amountOut < BigInt.zero) {
      return null;
    }
    final million = BigInt.from(1000000);
    final ratioPpm = (amountOut * refIn * million) ~/ (refOut * amountIn);
    // ไม้จริงได้อัตราดีกว่าไม้จิ๋ว (เศษจากการปัดของพูล) = ไม่กระทบ
    if (ratioPpm >= million) return 0;
    final impact = (1000000 - ratioPpm.toInt()) / 10000;
    return math.max(0, (impact * 100).round() / 100).toDouble();
  }

  /// ไม้นี้ต้องถูกบล็อกเพราะผลกระทบต่อราคาไหม — fail-closed
  ///
  /// เกินเพดาน 10% = บล็อกเสมอ · วัดไม่ได้ (null/NaN) = บล็อก เว้นแต่ผู้เรียกมีราคาตลาด
  /// ไว้เทียบและตรวจแล้วว่าราคาไม่เบี่ยงเกิน ([hasMarketReference] — หน้าเทรดเท่านั้น)
  static bool isPriceImpactBlocked(double? impact,
      {bool hasMarketReference = false}) {
    if (impact == null || !impact.isFinite) return !hasMarketReference;
    return impact > kBscMaxPriceImpactPct;
  }

  /// อ่านเงื่อนไขจากคำตอบของ `/swap/quote` — **fail-closed** (ฟังก์ชันเพียวไว้ทดสอบ)
  ///
  /// ไม่สำเร็จ / ไม่มี `fee_rate` ที่เป็นตัวเลข = โยน [SwapException] เสมอ ไม่มีอัตราปริยาย
  /// 404 หรือรหัส `INVALID_CHAIN` / `INVALID_TOKEN` / `PAIR_DISABLED` = แอดมินปิดคู่นี้
  ///
  /// slippage: ที่ผู้ใช้เลือก ([slippageOverride]) ชนะ → ค่าจาก backend → 0.5%
  /// (slippage เป็นค่าคุ้มครองผู้ใช้ ไม่ใช่ค่าธรรมเนียม — ค่าปริยายไม่ทำให้เก็บเงินผิด)
  static ({double feeRate, double slippage}) termsFromBackend(
    SwapQuoteResponse response, {
    double? slippageOverride,
  }) {
    const disabledCodes = {'INVALID_CHAIN', 'INVALID_TOKEN', 'PAIR_DISABLED'};
    final quote = response.quote;
    if (quote == null) {
      if (response.status == 404 ||
          disabledCodes.contains(response.errorCode)) {
        throw kSwapPairDisabled;
      }
      throw kSwapFeeConfigUnavailable;
    }

    final rawRate = quote['fee_rate'];
    final double? feeRate = rawRate is num
        ? rawRate.toDouble()
        : (rawRate is String ? double.tryParse(rawRate.trim()) : null);
    if (feeRate == null || !feeRate.isFinite || feeRate < 0 || feeRate >= 100) {
      throw kSwapFeeConfigUnavailable;
    }

    double slippage;
    if (slippageOverride != null &&
        slippageOverride.isFinite &&
        slippageOverride > 0) {
      slippage = slippageOverride;
    } else {
      final rawSlip = quote['slippage'];
      final backendSlip = rawSlip is num
          ? rawSlip.toDouble()
          : (rawSlip is String ? double.tryParse(rawSlip.trim()) : null);
      slippage = backendSlip != null && backendSlip.isFinite && backendSlip > 0
          ? backendSlip
          : 0.5;
    }
    // slippage 99% = minOut แทบเป็น 0 → โดน sandwich ได้ทั้งไม้ (เพดานเดียวกับฝั่งเชน TPIX)
    if (slippage > 50) {
      throw const SwapException(
        'Slippage must be between 0.01% and 50%.',
        'slippage ต้องอยู่ระหว่าง 0.01% ถึง 50%',
      );
    }
    return (feeRate: feeRate, slippage: slippage);
  }

  /// ตัดยอดของ [quote] ให้พอดียอดจริงบนเชน ([balanceWei]) — ฟังก์ชันเพียวไว้ทดสอบ
  ///
  /// - อ่านยอดไม่ได้ (null) หรือยอดพอ = คืน quote เดิม
  /// - เกินด้วยเศษจากการปัด (≤ 0.01% ของยอด) = ตัดให้พอดียอด แบ่งค่าธรรมเนียมใหม่ ไม่ปัดขึ้น
  /// - เกินจริง หรือเป็น BNB (ต้องเหลือไว้จ่ายค่าแก๊ส ไม่ตัดให้) = [kSwapInsufficientBalance]
  ///
  /// เดิมไม่เช็ก — พิมพ์เกินยอดนิดเดียว สวอปยังผ่าน (ใช้แค่ 99.7%) แต่ธุรกรรมค่าธรรมเนียม revert
  static BscSwapQuote clampToBalance(BscSwapQuote quote, BigInt? balanceWei) {
    final gross = quote.grossWei;
    if (balanceWei == null || gross <= balanceWei) return quote;

    final excess = gross - balanceWei;
    if (quote.fromToken.native || excess * BigInt.from(10000) > gross) {
      throw kSwapInsufficientBalance;
    }
    final split = splitPlatformFee(
        balanceWei, quote.feeWei > BigInt.zero ? quote.feeRate : 0);
    if (split.swapWei <= BigInt.zero) {
      throw const SwapException(
          'Amount is too small to swap.', 'จำนวนน้อยเกินไปสำหรับ swap');
    }
    // ยอดที่ได้ลดลงตามสัดส่วนของส่วนที่สวอป (ปัดลง)
    final rawOut = (quote.rawAmountOut * split.swapWei) ~/ quote.amountInSwapWei;
    final netOutput = fromWei(rawOut, quote.toToken.decimals);
    return BscSwapQuote(
      fromToken: quote.fromToken,
      toToken: quote.toToken,
      amountIn: fromWei(balanceWei, quote.fromToken.decimals),
      swapInput: fromWei(split.swapWei, quote.fromToken.decimals),
      feeAmount: fromWei(split.feeWei, quote.fromToken.decimals),
      feeRate: quote.feeRate,
      slippage: quote.slippage,
      netOutput: netOutput,
      minReceived:
          math.max(netOutput * (1 - quote.slippage / 100), 0).toDouble(),
      amountInSwapWei: split.swapWei,
      feeWei: split.feeWei,
      rawAmountOut: rawOut,
      path: quote.path,
      priceImpact: quote.priceImpact,
    );
  }

  // ── ตรวจ token กับ on-chain (fail-closed) ──

  /// คืน token ที่ตรวจ symbol()+decimals() กับเชนแล้ว
  /// symbol ไม่ตรง onchainSymbols หรืออ่านเชนไม่ได้ = throw ไม่ให้เทรด
  Future<VerifiedToken> verifyToken(String symbol) async {
    final key = symbol.toUpperCase();
    final entry = bscTradeToken(key);
    if (entry == null) {
      throw SwapException(
        '$key is not tradable on BSC yet.',
        '$key ยังเทรดบน BSC ไม่ได้',
      );
    }

    if (entry.native) {
      return VerifiedToken(
        symbol: key,
        address: entry.address,
        decimals: entry.decimals,
        native: true,
      );
    }

    final cached = _verifiedCache[key];
    if (cached != null) return cached;

    String onchainSymbol;
    int onchainDecimals;
    try {
      final contract = _erc20At(entry.address);
      final results = await _withClient((client) => Future.wait([
            client.call(
              contract: contract,
              function: contract.function('symbol'),
              params: const [],
            ),
            client.call(
              contract: contract,
              function: contract.function('decimals'),
              params: const [],
            ),
          ]));
      onchainSymbol = results[0].first as String;
      onchainDecimals = (results[1].first as BigInt).toInt();
    } catch (_) {
      // อ่านจากเชนไม่ได้ = ตรวจไม่ได้ = ไม่ให้เทรด (ห้าม fallback ค่า static)
      throw const SwapException(
        'Unable to verify token on BSC. Please try again.',
        'ตรวจสอบ token กับเชน BSC ไม่ได้ ลองใหม่อีกครั้ง',
      );
    }

    final accepted = (entry.onchainSymbols.isEmpty
            ? [key]
            : entry.onchainSymbols)
        .map((s) => s.toUpperCase());
    if (!accepted.contains(onchainSymbol.toUpperCase())) {
      throw SwapException(
        'Token verification failed for $key. Trading blocked for safety.',
        'ตรวจสอบ token $key ไม่ผ่าน — ระงับการเทรดเพื่อความปลอดภัย',
      );
    }

    final verified = VerifiedToken(
      symbol: key,
      address: entry.address,
      decimals: onchainDecimals,
      native: false,
    );
    _verifiedCache[key] = verified;
    return verified;
  }

  // ── ยอดคงเหลือบน BSC ──

  /// ยอดของ [symbol] (จาก registry) ของ [walletAddress] — อ่านจาก BSC ตรง
  /// คืน 0 เมื่ออ่านไม่ได้ (ใช้แสดงผลเท่านั้น executeSwap เช็คของจริงบนเชนอีกที)
  Future<double> getBalance(String symbol, String walletAddress) async {
    final entry = bscTradeToken(symbol);
    if (entry == null) return 0;
    try {
      final owner = EthereumAddress.fromHex(walletAddress);
      if (entry.native) {
        final amount =
            await _withClient((client) => client.getBalance(owner));
        return fromWei(amount.getInWei, 18);
      }
      final contract = _erc20At(entry.address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('balanceOf'),
            params: [owner],
          ));
      final decimals =
          _verifiedCache[symbol.toUpperCase()]?.decimals ?? entry.decimals;
      return fromWei(result.first as BigInt, decimals);
    } catch (_) {
      return 0;
    }
  }

  /// ยอดจริงของ [token] เป็นหน่วยเล็กสุด — **null = อ่านไม่ได้** (ไม่ใช่ 0)
  /// ใช้ตัดยอดของไม้ให้พอดียอดบนเชนก่อนส่ง ([clampToBalance])
  Future<BigInt?> getBalanceWei(VerifiedToken token, String walletAddress) async {
    final reader = debugBalanceReader;
    if (reader != null) return reader(token, walletAddress);
    try {
      final owner = EthereumAddress.fromHex(walletAddress);
      if (token.native) {
        final amount =
            await _withClient((client) => client.getBalance(owner));
        return amount.getInWei;
      }
      final contract = _erc20At(token.address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('balanceOf'),
            params: [owner],
          ));
      return result.first as BigInt;
    } catch (_) {
      return null;
    }
  }

  /// ตัดยอดของ [quote] ให้พอดียอดจริงบนเชนที่อ่าน ณ ตอนนี้ — เรียกก่อน approve
  /// ([executeMarketSwap] อ่านซ้ำอีกครั้งก่อนส่งจริง) · อ่านยอดไม่ได้ = คืน quote เดิม
  /// (กระเป๋า/เชนจะปฏิเสธเองถ้ายอดไม่พอจริง — เหมือนเว็บ)
  Future<BscSwapQuote> fitToBalance(
          BscSwapQuote quote, String walletAddress) async =>
      clampToBalance(
          quote, await getBalanceWei(quote.fromToken, walletAddress));

  // ── จุดเสียบสำหรับเทสต์ (ไม่ยิงเน็ต/เชนจริง) ──

  /// แทนการยิง `/swap/quote` จริง
  @visibleForTesting
  Future<SwapQuoteResponse> Function({
    required String fromToken,
    required String toToken,
    required String amount,
    required int chainId,
  })? debugQuoteFetcher;

  /// แทนการเรียก router.getAmountsOut จริง — คืนยอดขาสุดท้าย หรือ null เมื่อ revert
  @visibleForTesting
  Future<BigInt?> Function(BigInt amountIn, List<EthereumAddress> path)?
      debugAmountsOut;

  /// แทนการอ่านยอดจากเชน
  @visibleForTesting
  Future<BigInt?> Function(VerifiedToken token, String walletAddress)?
      debugBalanceReader;

  /// แทนการถามที่อยู่ fee collector จากเซิร์ฟเวอร์
  @visibleForTesting
  Future<Map<String, dynamic>?> Function()? debugFeeInfoFetcher;

  /// แทนการรอ receipt จากเชน — คืน true/false = สถานะ, null = ยังไม่ยืนยัน
  @visibleForTesting
  Future<bool?> Function(String txHash)? debugReceiptStatus;

  /// แทนคิวบันทึกไม้ตัวจริง (ตัวจริงยิง POST /swap/execute)
  @visibleForTesting
  PendingSwapRecords? debugQueue;

  /// ใส่โทเคนที่ "ตรวจกับเชนแล้ว" ลงแคชตรง ๆ
  @visibleForTesting
  void debugSeedVerified(VerifiedToken token) =>
      _verifiedCache[token.symbol.toUpperCase()] = token;

  /// ล้างจุดเสียบและแคชทั้งหมด (เรียกใน tearDown — service เป็น singleton)
  @visibleForTesting
  void debugReset() {
    debugQuoteFetcher = null;
    debugAmountsOut = null;
    debugBalanceReader = null;
    debugFeeInfoFetcher = null;
    debugReceiptStatus = null;
    debugQueue = null;
    _verifiedCache.clear();
    _feeCollector = null;
    _feeCollectorAt = null;
  }

  // ── Quote จาก router จริง ──

  /// ขอ quote จริงจาก PancakeSwap — อัตราค่าธรรมเนียม/slippage มาจาก backend แบบ **fail-closed**
  ///
  /// ⚠️ เดิมคำขอ `/swap/quote` ล้มแล้วเงียบ ใช้ 0.3% / 0.5% เดาเองแล้วสวอปต่อ:
  ///    - แอดมินปิดเชน/คู่ฉุกเฉิน (404) แต่สวอปยังลงเชนจริง แล้วบันทึกไม่ได้ตลอดไป
  ///    - อัตราจริงไม่ใช่ 0.3% → เก็บผิดอัตรา เซิร์ฟเวอร์ตีตก FEE_MISMATCH → ไม้หายจากประวัติ
  ///    ไม่รู้อัตราจริง = ไม่มีราคา = โยน [SwapException] (ดู [termsFromBackend])
  ///
  /// [amountText] (ถ้าให้มา) = จำนวนที่จ่าย ตามที่ผู้ใช้พิมพ์ — แปลงเป็นหน่วยเล็กสุดแบบตรงตัว
  /// ไม่ผ่าน double (ไม่ปัดเกินยอดจริง) · [amountWei] = หน่วยเล็กสุดที่ผู้เรียกคิดมาแล้ว
  /// ลำดับ: [amountWei] → [amountText] → [amount] (double ตัดทศนิยมทิ้ง)
  /// throw [SwapException] เมื่อไม่มีสภาพคล่อง/จำนวนไม่ถูกต้อง/คู่ถูกปิด
  Future<BscSwapQuote> getQuote({
    required String fromSymbol,
    required String toSymbol,
    required double amount,
    double? slippageOverride,
    String? amountText,
    BigInt? amountWei,
  }) async {
    if (amountWei == null && amountText == null && !(amount > 0)) {
      throw const SwapException('Enter an amount.', 'กรุณาระบุจำนวน');
    }

    final fromTok = await verifyToken(fromSymbol);
    final toTok = await verifyToken(toSymbol);

    // ยอดรวมที่ผู้ใช้จ่าย (swap + fee) เป็นหน่วยเล็กสุด — ตัดทศนิยมทิ้ง ไม่ปัดขึ้น
    BigInt? parsed = amountWei;
    if (parsed == null && amountText != null) {
      parsed = parseUnits(amountText, fromTok.decimals);
      if (parsed == null) {
        throw const SwapException(
            'Enter a valid amount.', 'กรอกจำนวนให้ถูกต้อง');
      }
    }
    final grossWei = parsed ?? toWei(amount, fromTok.decimals);
    if (grossWei <= BigInt.zero) {
      throw const SwapException(
          'Amount is too small to swap.', 'จำนวนน้อยเกินไปสำหรับ swap');
    }
    final grossAmount = fromWei(grossWei, fromTok.decimals);

    // 1) อัตราค่าธรรมเนียม / slippage จาก backend — FAIL-CLOSED (ตัวเลขราคาไม่ใช้ — ใช้ router จริง)
    final fetch = debugQuoteFetcher ?? ApiService().getSwapQuote;
    final SwapQuoteResponse backend;
    try {
      backend = await fetch(
        fromToken: fromTok.address,
        toToken: toTok.address,
        amount: unitsToDecimal(grossWei, fromTok.decimals),
        chainId: chainId,
      );
    } catch (e) {
      debugPrint('swap quote terms failed: ${e.runtimeType}');
      throw kSwapFeeConfigUnavailable;
    }
    final terms = termsFromBackend(backend, slippageOverride: slippageOverride);
    final feeRate = terms.feeRate;
    final slippage = terms.slippage;

    // 2) หัก fee จากฝั่ง input แล้วกันไว้ (BigInt ล้วน) — swap เฉพาะส่วนที่เหลือ
    //    (swap + fee) รวมได้เท่ายอดที่ผู้ใช้จ่ายพอดีเสมอ
    final split = splitPlatformFee(grossWei, feeRate);
    final feeWei = split.feeWei;
    final amountInSwapWei = split.swapWei;
    if (amountInSwapWei <= BigInt.zero) {
      throw feeWei > BigInt.zero
          ? const SwapException('Amount is too small after fees.',
              'จำนวนน้อยเกินไปหลังหักค่าธรรมเนียม')
          : const SwapException(
              'Amount is too small to swap.', 'จำนวนน้อยเกินไปสำหรับ swap');
    }
    final feeAmount = fromWei(feeWei, fromTok.decimals);
    final swapInput = fromWei(amountInSwapWei, fromTok.decimals);

    // 3) Routing path — ตรงถ้าฝั่งใดฝั่งหนึ่งเป็น WBNB ไม่งั้นวิ่งผ่าน WBNB
    final fromAddr = _routingAddress(fromTok);
    final toAddr = _routingAddress(toTok);
    final wbnb = kWbnbAddress.toLowerCase();
    List<EthereumAddress> path;
    if (fromAddr.toLowerCase() == wbnb || toAddr.toLowerCase() == wbnb) {
      path = [
        EthereumAddress.fromHex(fromAddr),
        EthereumAddress.fromHex(toAddr),
      ];
    } else {
      path = [
        EthereumAddress.fromHex(fromAddr),
        EthereumAddress.fromHex(kWbnbAddress),
        EthereumAddress.fromHex(toAddr),
      ];
    }

    // 4) ราคาจริงจาก router — path 3 ขาล้มเหลวลอง direct 1 ครั้ง (เหมือนเว็บ)
    BigInt? amountOut = await _getAmountsOut(amountInSwapWei, path);
    if (amountOut == null && path.length == 3) {
      path = [path.first, path.last];
      amountOut = await _getAmountsOut(amountInSwapWei, path);
    }
    if (amountOut == null || amountOut <= BigInt.zero) {
      throw const SwapException(
        'No liquidity available for this token pair.',
        'คู่เหรียญนี้ไม่มีสภาพคล่องบน BSC',
      );
    }

    // 5) ผลกระทบต่อราคาจริง — เทียบกับไม้จิ๋ว (0.1%) บนเส้นทางเดียวกัน (พูลเดียวกัน)
    //    อ่านไม่ได้ = null ("ไม่รู้") ไม่ใช่ 0 ("ไม่กระทบ") — ผู้เรียกต้องบล็อกเมื่อไม่รู้
    double? priceImpact;
    final refIn = amountInSwapWei ~/ BigInt.from(1000);
    if (refIn > BigInt.zero) {
      final refOut = await _getAmountsOut(refIn, path);
      priceImpact = refOut == null
          ? null
          : priceImpactPct(
              amountIn: amountInSwapWei,
              amountOut: amountOut,
              refIn: refIn,
              refOut: refOut,
            );
    }

    final netOutput = fromWei(amountOut, toTok.decimals);
    final minReceived =
        math.max(netOutput * (1 - slippage / 100), 0).toDouble();

    return BscSwapQuote(
      fromToken: fromTok,
      toToken: toTok,
      amountIn: grossAmount,
      swapInput: swapInput,
      feeAmount: feeAmount,
      feeRate: feeRate,
      slippage: slippage,
      netOutput: netOutput,
      minReceived: minReceived,
      amountInSwapWei: amountInSwapWei,
      feeWei: feeWei,
      rawAmountOut: amountOut,
      path: path,
      priceImpact: priceImpact,
    );
  }

  String _routingAddress(VerifiedToken token) =>
      token.native ? kWbnbAddress : token.address;

  Future<BigInt?> _getAmountsOut(
      BigInt amountIn, List<EthereumAddress> path) async {
    final fake = debugAmountsOut;
    if (fake != null) return fake(amountIn, path);
    try {
      final contract = _routerContract;
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('getAmountsOut'),
            params: [amountIn, path],
          ));
      final amounts = (result.first as List).cast<BigInt>();
      return amounts.isNotEmpty ? amounts.last : null;
    } catch (e) {
      debugPrint('getAmountsOut failed: ${e.runtimeType}');
      return null;
    }
  }

  // ── Allowance / Approve ──

  /// Allowance ของ router พอสำหรับ [wei] ไหม — native ไม่ต้อง approve
  Future<bool> hasAllowance(
      VerifiedToken token, String owner, BigInt wei) async {
    if (token.native) return true;
    try {
      final contract = _erc20At(token.address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('allowance'),
            params: [
              EthereumAddress.fromHex(owner),
              EthereumAddress.fromHex(kPancakeRouterAddress),
            ],
          ));
      return (result.first as BigInt) >= wei;
    } catch (_) {
      return false;
    }
  }

  /// Calldata approve router **เท่ากับยอดที่จะ swap พอดี** (ไม่ใช่ไม่จำกัด)
  /// เดิม approve 2^256−1 ค้างไว้ตลอด — router มีปัญหาวันไหน ยอดทั้งกระเป๋าเปิดให้ดึง
  Uint8List approveCalldata(BigInt amount) {
    final contract = _erc20At(kWbnbAddress); // ABI ใช้ร่วมกันทุก ERC20
    return contract.function('approve').encodeCall([
      EthereumAddress.fromHex(kPancakeRouterAddress),
      amount,
    ]);
  }

  // ── Fee collector (fail-closed) ──

  String? _feeCollector;
  DateTime? _feeCollectorAt;
  static const Duration _feeCollectorTtl = Duration(seconds: 60);

  /// ที่อยู่กระเป๋ารับค่าธรรมเนียมของแพลตฟอร์ม — **ต้องรู้ก่อนขอให้ผู้ใช้ approve**
  ///
  /// FAIL-CLOSED: เซิร์ฟเวอร์ไม่ตอบ / ที่อยู่ผิดรูป = โยน [SwapException] ไม่ให้เทรด
  /// (กันเคส "swap สำเร็จแต่เก็บ fee ไม่ได้" ซึ่งตรวจย้อนหลังไม่ได้ — บทเรียน 2026-07-11)
  /// เดิมเช็กตอนจะสวอป — ผู้ใช้จ่ายค่าแก๊ส approve ไปแล้วค่อยเจอ "ระบบยังไม่พร้อม"
  /// จำค่าไว้ 60 วิ (เท่ากับเว็บ) — ขั้น approve กับขั้น swap จึงถามเซิร์ฟเวอร์ครั้งเดียว
  Future<String> ensureFeeCollector() async {
    final cached = _feeCollector;
    final at = _feeCollectorAt;
    if (cached != null &&
        at != null &&
        DateTime.now().difference(at) < _feeCollectorTtl) {
      return cached;
    }
    Map<String, dynamic>? info;
    try {
      final fake = debugFeeInfoFetcher;
      info = fake != null
          ? await fake()
          : await ApiService().getTradingFeeInfo(chainId: chainId);
    } catch (e) {
      debugPrint('fee collector lookup failed: ${e.runtimeType}');
    }
    final address = info?['fee_collector'];
    if (address is! String ||
        !RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(address)) {
      _feeCollector = null;
      _feeCollectorAt = null;
      throw const SwapException(
        'Trading is temporarily unavailable. Please try again later.',
        'ระบบเทรดยังไม่พร้อมใช้งานชั่วคราว ลองใหม่ภายหลัง',
      );
    }
    _feeCollector = address;
    _feeCollectorAt = DateTime.now();
    return address;
  }

  // ── Execute ──

  /// ส่ง swap จริงตาม [quote] — ลำดับเหมือนเว็บทุกขั้น:
  /// fee collector (fail-closed) → ตัดยอดให้พอดียอดบนเชน → ด่าน price impact → swap
  /// → เข้าคิวบันทึกทันทีที่รู้ hash → รอ receipt → โอน fee (best-effort) → แจ้งบันทึก (คิวส่งซ้ำให้)
  ///
  /// [sendTx] มาจาก WalletProvider — เป็นผู้เดียวที่แตะ key/session
  /// [meta] = คู่/ฝั่ง/ราคา จากกระดานเทรด (ให้ประวัติอ่านรู้เรื่อง)
  /// [minOutFloor] = "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นบนจอก่อนกด — minOut บนเชนจะไม่ต่ำกว่านี้
  /// [allowUnknownImpact] = ผู้เรียกมีราคาตลาดเทียบและตรวจแล้วว่าไม่เบี่ยงเกิน (หน้าเทรดเท่านั้น)
  Future<SwapResult> executeMarketSwap({
    required BscSwapQuote quote,
    required String walletAddress,
    required BscTxSender sendTx,
    SwapRecordMeta? meta,
    BigInt? minOutFloor,
    bool allowUnknownImpact = false,
  }) async {
    // ด่านสุดท้ายก่อนแตะเงิน — ไม่ว่าหน้าจอจะเช็กมาแล้วหรือยัง
    final impact = quote.priceImpact;
    if (impact != null && impact.isFinite && impact > kBscMaxPriceImpactPct) {
      throw swapImpactTooHigh(impact);
    }
    if (isPriceImpactBlocked(impact, hasMarketReference: allowUnknownImpact)) {
      throw kSwapImpactUnknown;
    }

    // FAIL-CLOSED: ต้องรู้ที่อยู่ fee collector ก่อนส่ง swap
    String? feeCollector;
    if (quote.feeWei > BigInt.zero) {
      feeCollector = await ensureFeeCollector();
    }

    // ยอดที่จะจ่ายต้องไม่เกินยอดจริงบนเชน ณ ตอนนี้ — เกินด้วยเศษ = ตัดให้พอดี · เกินจริง = ไม่ส่ง
    quote = clampToBalance(
        quote, await getBalanceWei(quote.fromToken, walletAddress));

    // minOut จาก slippage เท่านั้น และไม่ต่ำกว่าที่ผู้ใช้เห็นบนจอก่อนกด
    var minOut = minOutFor(quote.rawAmountOut, quote.slippage);
    if (minOutFloor != null &&
        minOutFloor > minOut &&
        minOutFloor <= quote.rawAmountOut) {
      minOut = minOutFloor;
    }
    if (minOut <= BigInt.zero) {
      throw const SwapException(
          'Slippage too high — please adjust and retry.',
          'ค่า slippage สูงเกินไป ปรับแล้วลองใหม่');
    }

    final deadline = BigInt.from(
        DateTime.now().millisecondsSinceEpoch ~/ 1000 + 1200); // 20 นาที
    final recipient = EthereumAddress.fromHex(walletAddress);
    final contract = _routerContract;

    // เลือก swap variant ตามชนิดของ token ต้นทาง/ปลายทาง
    Uint8List data;
    BigInt? txValue;
    if (quote.fromToken.native) {
      data = contract.function('swapExactETHForTokens').encodeCall([
        minOut,
        quote.path,
        recipient,
        deadline,
      ]);
      txValue = quote.amountInSwapWei;
    } else if (quote.toToken.native) {
      data = contract.function('swapExactTokensForETH').encodeCall([
        quote.amountInSwapWei,
        minOut,
        quote.path,
        recipient,
        deadline,
      ]);
    } else {
      data = contract.function('swapExactTokensForTokens').encodeCall([
        quote.amountInSwapWei,
        minOut,
        quote.path,
        recipient,
        deadline,
      ]);
    }

    final txHash = await sendTx(
      to: kPancakeRouterAddress,
      data: data,
      value: txValue,
    );
    if (txHash == null || txHash.isEmpty) {
      throw const SwapException(
          'Transaction rejected or failed to send.',
          'ธุรกรรมถูกปฏิเสธหรือส่งไม่สำเร็จ');
    }

    /*
     * ⚠️ เก็บบันทึกลงคิวในเครื่อง "ทันที" ที่ธุรกรรมออกจากกระเป๋า — ก่อนรอลงบล็อก
     *    เดิมแจ้งเซิร์ฟเวอร์ครั้งเดียวตอนท้ายสุดแบบ best-effort: แอปถูกปิดระหว่างรอ / 403 / 5xx
     *    = ไม้จริงหายจากประวัติถาวร และค่าธรรมเนียมไม่เคยถูกตรวจ
     *    ระหว่างขั้นค่าธรรมเนียม ตัว flush ต้องข้ามแถวนี้ (markInflight) ไม่งั้นบันทึกชั่วคราว
     *    (fee_collected:false) ถูกส่งไปก่อนบันทึกจริง
     */
    final queue = debugQueue ?? PendingSwapRecords.I;
    Map<String, dynamic> recordFor({
      required double feeAmount,
      required bool feeCollected,
      String? feeTxHash,
    }) =>
        PendingSwapRecords.buildRecord(
          fromToken: quote.fromToken.address,
          toToken: quote.toToken.address,
          fromAmount: quote.amountIn,
          toAmount: quote.netOutput,
          feeAmount: feeAmount,
          txHash: txHash,
          chainId: chainId,
          walletAddress: walletAddress,
          feeCollected: feeCollected,
          feeTxHash: feeTxHash,
          pair: meta?.pair,
          side: meta?.side,
          price: meta?.price,
        );

    queue.markInflight(txHash);
    var feeCollected = false;
    bool confirmed;
    try {
      await queue.upsert(recordFor(
        feeAmount: 0,
        // ยังไม่ได้โอนค่าธรรมเนียม — เซิร์ฟเวอร์นับเฉพาะธุรกรรมโอนที่อ่านเจอบนเชน
        feeCollected: !(quote.feeWei > BigInt.zero),
      ));

      // รอ receipt — 3 วิ × 40 ครั้ง (~2 นาที)
      final status = await _receiptStatus(txHash, attempts: 40);
      if (status == false) {
        // ไม้ไม่ลง — ไม่มีอะไรให้บันทึก (เซิร์ฟเวอร์จะตอบ 422 TX_FAILED อยู่ดี)
        await queue.remove(txHash);
        throw const SwapException(
            'Swap failed on-chain. Try a smaller amount or higher slippage.',
            'Swap ล้มเหลวบนเชน ลองจำนวนน้อยลงหรือเพิ่ม slippage');
      }
      confirmed = status == true;

      // โอน fee ที่กันไว้ — ผู้ใช้ยังถือครบเพราะ swap แค่ (amount - fee)
      // Best-effort เหมือนเว็บ: fee พลาดไม่ block ผล swap ที่สำเร็จแล้ว
      // hash ของธุรกรรม fee ส่งให้เซิร์ฟเวอร์ตรวจบนเชนเอง — รอผลไม่ทันก็ยังตรวจย้อนหลังได้
      String? feeTxHash;
      if (confirmed && quote.feeWei > BigInt.zero && feeCollector != null) {
        try {
          if (quote.fromToken.native) {
            feeTxHash = await sendTx(to: feeCollector, value: quote.feeWei);
          } else {
            final tokenContract = _erc20At(quote.fromToken.address);
            final feeData = tokenContract.function('transfer').encodeCall([
              EthereumAddress.fromHex(feeCollector),
              quote.feeWei,
            ]);
            feeTxHash =
                await sendTx(to: quote.fromToken.address, data: feeData);
          }
          if (feeTxHash != null && feeTxHash.isNotEmpty) {
            feeCollected =
                await _receiptStatus(feeTxHash, attempts: 20) == true;
          } else {
            feeTxHash = null;
          }
        } catch (e) {
          debugPrint('fee collection skipped: ${e.runtimeType}');
        }
      }

      // บันทึกจริง (แทนที่แถวชั่วคราวของ tx นี้ในคิว)
      await queue.upsert(recordFor(
        feeAmount: feeTxHash != null ? quote.feeAmount : 0,
        feeCollected: quote.feeWei > BigInt.zero ? feeCollected : true,
        feeTxHash: feeTxHash,
      ));
    } finally {
      queue.clearInflight(txHash);
    }

    // แจ้งเซิร์ฟเวอร์ — ไม่สำเร็จ (เน็ตหลุด / 403 / 5xx / เชนยังไม่เห็น) แถวยังอยู่ในคิว ส่งใหม่ให้เอง
    final outcome = await queue.submit(txHash);

    return SwapResult(
      txHash: txHash,
      explorerUrl: '${ChainConfig.bsc.explorerUrl}/tx/$txHash',
      confirmed: confirmed,
      feeCollected: feeCollected,
      recorded: outcome == SwapRecordOutcome.recorded,
      quote: quote,
    );
  }

  /// รอจน tx ยืนยันบนเชน — true = สำเร็จ, false = fail หรือรอนานเกิน
  /// (ใช้รอ approve ก่อนยิง swap ต่อ)
  Future<bool> waitConfirmed(String txHash, {int attempts = 40}) async =>
      await _receiptStatus(txHash, attempts: attempts) == true;

  /// สถานะของธุรกรรม: true = สำเร็จ · false = ล้มบนเชน · null = ยังไม่ยืนยันในเวลาที่รอ
  Future<bool?> _receiptStatus(String txHash, {int attempts = 40}) async {
    final fake = debugReceiptStatus;
    if (fake != null) return fake(txHash);
    final receipt = await _waitReceipt(txHash, attempts: attempts);
    return receipt?.status;
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
      await Future.delayed(const Duration(seconds: 3));
    }
    return null; // ยังไม่ยืนยันใน ~2 นาที — ถือว่า pending ไม่ใช่ fail
  }
}
