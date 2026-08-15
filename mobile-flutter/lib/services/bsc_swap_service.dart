/// TPIX TRADE — BSC Swap Service (mobile)
/// Engine สำหรับเทรดจริงบน BSC ผ่าน PancakeSwap V2 — mirror มาจาก
/// useSwap.js ฝั่งเว็บ ให้พฤติกรรมสอดคล้องกันที่สุด:
///
///  - ราคา/ยอด/allowance อ่านจาก BSC RPC ตรง (ไม่ขึ้นกับเชนของ wallet)
///  - ค่าธรรมเนียมแพลตฟอร์มหักจากฝั่ง INPUT แล้วกันไว้ (fee reserve):
///    swap เฉพาะ (amount - fee) → โอน fee แยกหลัง swap สำเร็จ ไม่มีทางเกินยอด
///  - minOut คิดจาก slippage เท่านั้น (fee ไม่ไปกดยอดที่ผู้ใช้ได้รับ)
///  - FAIL-CLOSED: ไม่รู้ที่อยู่ fee collector = ไม่ swap (กัน fee หายเงียบ)
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

/// Error ที่มีข้อความพร้อมแสดงต่อผู้ใช้ทั้ง 2 ภาษา
class SwapException implements Exception {
  final String en;
  final String th;
  const SwapException(this.en, this.th);

  String message(bool isThai) => isThai ? th : en;

  @override
  String toString() => en;
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
  });
}

/// ผลการ swap ที่ส่งขึ้นเชนแล้ว
class SwapResult {
  final String txHash;
  final String explorerUrl;
  final bool confirmed; // false = ส่งแล้วแต่รอยืนยันนานเกิน (ไม่ใช่ fail)
  final bool feeCollected;

  const SwapResult({
    required this.txHash,
    required this.explorerUrl,
    required this.confirmed,
    required this.feeCollected,
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

  /// แปลงจำนวน (double) → wei ตาม decimals — จำกัดทศนิยม 12 หลักกัน float
  /// garbage หลักท้าย (fee reserve คือ margin ความปลอดภัยของ MAX อยู่แล้ว
  /// — pattern เดียวกับ toWei ของเว็บ)
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

  /// แปลง wei → double สำหรับแสดงผล
  static double fromWei(BigInt wei, int decimals) =>
      wei.toDouble() / math.pow(10, decimals);

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

  // ── Quote จาก router จริง ──

  /// ขอ quote จริงจาก PancakeSwap — fee/slippage config มาจาก backend
  /// (default 0.3% / 0.5% ถ้า backend ล่ม — เหมือนเว็บ)
  /// throw [SwapException] เมื่อไม่มีสภาพคล่อง/จำนวนไม่ถูกต้อง
  Future<BscSwapQuote> getQuote({
    required String fromSymbol,
    required String toSymbol,
    required double amount,
    double? slippageOverride,
  }) async {
    if (amount <= 0) {
      throw const SwapException('Enter an amount.', 'กรุณาระบุจำนวน');
    }

    final fromTok = await verifyToken(fromSymbol);
    final toTok = await verifyToken(toSymbol);

    // 1) Fee/slippage config จาก backend (ตัวเลขราคาเราไม่ใช้ — ใช้ router จริง)
    double feeRate = 0.3;
    double slippage = slippageOverride ?? 0.5;
    try {
      final backendQuote = await ApiService().getSwapQuote(
        fromToken: fromTok.address,
        toToken: toTok.address,
        amount: amount,
        chainId: chainId,
      );
      final q = backendQuote?['quote'] as Map<String, dynamic>? ?? backendQuote;
      final rate = q?['fee_rate'];
      if (rate is num) feeRate = rate.toDouble();
      if (slippageOverride == null) {
        final slip = q?['slippage'];
        if (slip is num) slippage = slip.toDouble();
      }
    } catch (_) {
      // ใช้ default — quote ราคาจริงยังมาจาก router ข้างล่างเสมอ
    }

    // 2) หัก fee จากฝั่ง input แล้วกันไว้ — swap เฉพาะส่วนที่เหลือ
    final feeAmount = amount * (feeRate / 100);
    final swapInput = amount - feeAmount;
    if (swapInput <= 0) {
      throw const SwapException(
          'Amount is too small after fees.', 'จำนวนน้อยเกินไปหลังหักค่าธรรมเนียม');
    }
    final grossWei = toWei(amount, fromTok.decimals);
    final amountInSwapWei = toWei(swapInput, fromTok.decimals);
    if (amountInSwapWei <= BigInt.zero) {
      throw const SwapException(
          'Amount is too small to swap.', 'จำนวนน้อยเกินไปสำหรับ swap');
    }
    // fee = ส่วนต่างเป๊ะๆ — (swap + fee) รวมได้เท่ายอดที่ผู้ใช้ถือจริง
    final feeWei = grossWei > amountInSwapWei
        ? grossWei - amountInSwapWei
        : BigInt.zero;

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

    final netOutput = fromWei(amountOut, toTok.decimals);
    final minReceived =
        math.max(netOutput * (1 - slippage / 100), 0).toDouble();

    return BscSwapQuote(
      fromToken: fromTok,
      toToken: toTok,
      amountIn: amount,
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
    );
  }

  String _routingAddress(VerifiedToken token) =>
      token.native ? kWbnbAddress : token.address;

  Future<BigInt?> _getAmountsOut(
      BigInt amountIn, List<EthereumAddress> path) async {
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

  /// Calldata สำหรับ approve router แบบไม่จำกัด (pattern เดียวกับเว็บ)
  Uint8List approveCalldata() {
    final contract = _erc20At(kWbnbAddress); // ABI ใช้ร่วมกันทุก ERC20
    final maxUint = (BigInt.one << 256) - BigInt.one;
    return contract.function('approve').encodeCall([
      EthereumAddress.fromHex(kPancakeRouterAddress),
      maxUint,
    ]);
  }

  // ── Execute ──

  /// ส่ง swap จริงตาม [quote] — ลำดับเหมือนเว็บทุกขั้น:
  /// fee collector (fail-closed) → swap → รอ receipt → โอน fee (best-effort)
  /// → บันทึก backend (best-effort)
  ///
  /// [sendTx] มาจาก WalletProvider — เป็นผู้เดียวที่แตะ key/session
  Future<SwapResult> executeMarketSwap({
    required BscSwapQuote quote,
    required String walletAddress,
    required BscTxSender sendTx,
  }) async {
    // FAIL-CLOSED: ต้องรู้ที่อยู่ fee collector ก่อนส่ง swap — ไม่งั้นเกิดเคส
    // "swap สำเร็จแต่เก็บ fee ไม่ได้" ซึ่งตรวจย้อนหลังไม่ได้ (บทเรียน 2026-07-11)
    String? feeCollector;
    if (quote.feeWei > BigInt.zero) {
      final info = await ApiService().getTradingFeeInfo(chainId: chainId);
      feeCollector = info?['fee_collector'] as String?;
      final valid = feeCollector != null &&
          RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(feeCollector);
      if (!valid) {
        throw const SwapException(
          'Trading is temporarily unavailable. Please try again later.',
          'ระบบเทรดยังไม่พร้อมใช้งานชั่วคราว ลองใหม่ภายหลัง',
        );
      }
    }

    // minOut จาก slippage เท่านั้น — คิดเป็น basis point ป้องกัน float
    final slipBps = BigInt.from(
        math.max(0, ((1 - quote.slippage / 100) * 10000).floor()));
    final minOut = (quote.rawAmountOut * slipBps) ~/ BigInt.from(10000);
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

    // รอ receipt — 3 วิ × 40 ครั้ง (~2 นาที)
    final receipt = await _waitReceipt(txHash, attempts: 40);
    if (receipt != null && receipt.status == false) {
      throw const SwapException(
          'Swap failed on-chain. Try a smaller amount or higher slippage.',
          'Swap ล้มเหลวบนเชน ลองจำนวนน้อยลงหรือเพิ่ม slippage');
    }
    final confirmed = receipt?.status == true;

    // โอน fee ที่กันไว้ — ผู้ใช้ยังถือครบเพราะ swap แค่ (amount - fee)
    // Best-effort เหมือนเว็บ: fee พลาดไม่ block ผล swap ที่สำเร็จแล้ว
    var feeCollected = false;
    if (confirmed && quote.feeWei > BigInt.zero && feeCollector != null) {
      try {
        String? feeTxHash;
        if (quote.fromToken.native) {
          feeTxHash = await sendTx(to: feeCollector, value: quote.feeWei);
        } else {
          final tokenContract = _erc20At(quote.fromToken.address);
          final feeData = tokenContract.function('transfer').encodeCall([
            EthereumAddress.fromHex(feeCollector),
            quote.feeWei,
          ]);
          feeTxHash = await sendTx(to: quote.fromToken.address, data: feeData);
        }
        if (feeTxHash != null && feeTxHash.isNotEmpty) {
          final feeReceipt = await _waitReceipt(feeTxHash, attempts: 20);
          feeCollected = feeReceipt?.status == true;
        }
      } catch (e) {
        debugPrint('fee collection skipped: ${e.runtimeType}');
      }
    }

    // บันทึกฝั่ง backend (best-effort — เหมือนเว็บ)
    try {
      await ApiService().recordSwapExecution(
        fromToken: quote.fromToken.address,
        toToken: quote.toToken.address,
        fromAmount: quote.amountIn,
        toAmount: quote.netOutput,
        feeAmount: feeCollected ? quote.feeAmount : 0,
        txHash: txHash,
        chainId: chainId,
        walletAddress: walletAddress,
      );
    } catch (e) {
      debugPrint('recordSwapExecution failed: ${e.runtimeType}');
    }

    return SwapResult(
      txHash: txHash,
      explorerUrl: '${ChainConfig.bsc.explorerUrl}/tx/$txHash',
      confirmed: confirmed,
      feeCollected: feeCollected,
    );
  }

  /// รอจน tx ยืนยันบนเชน — true = สำเร็จ, false = fail หรือรอนานเกิน
  /// (ใช้รอ approve ก่อนยิง swap ต่อ)
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
      await Future.delayed(const Duration(seconds: 3));
    }
    return null; // ยังไม่ยืนยันใน ~2 นาที — ถือว่า pending ไม่ใช่ fail
  }
}
