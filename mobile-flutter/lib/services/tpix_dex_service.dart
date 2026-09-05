/// TPIX TRADE — TPIX DEX Service (mobile)
/// เทรดจริงบนเชน TPIX (4289) ผ่าน TPIXDEXRouter02 (AMM แบบ Uniswap V2)
/// mirror มาจาก useTpixDex.js ฝั่งเว็บ ให้พฤติกรรมสองฝั่งตรงกันที่สุด
///
///  - ที่อยู่สัญญา **มาจากทะเบียนบนเซิร์ฟเวอร์** (`/api/v1/dex/config`) ไม่ hardcode
///    ที่อยู่ในแอปเด็ดขาด เพราะ deploy ใหม่แล้วแอปเก่าจะชี้ไปที่ตายทันที
///  - fail-closed: เซิร์ฟเวอร์ยังไม่ตอบว่า ready = ทุก action คืน error ไม่เดา address
///  - ราคา/ยอด/allowance อ่านจาก RPC ของเชน TPIX ตรง ไม่ขึ้นกับเชนที่กระเป๋าอยู่
///  - ค่าธรรมเนียม 0.3% **อยู่ในพูล** (LP + feeTo) ไม่มีธุรกรรมโอนค่าธรรมเนียมแยก
///    ต่างจากฝั่ง BSC ที่ต้องโอนใบที่สอง — จึงไม่มี fee reserve ที่นี่
///  - price impact คิดจากส่วนต่างระหว่างราคาที่ได้จริงกับราคากลางของพูล
///
/// การเซ็น/ส่งธุรกรรมเป็นหน้าที่ของ caller (WalletProvider) ผ่าน [DexTxSender]
/// — service นี้ไม่แตะ private key เด็ดขาด (กติกาเดียวกับ BscSwapService)
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:web3dart/web3dart.dart';

import '../models/chain_config.dart';
import 'api_service.dart';
// ใช้ชนิดข้อมูลร่วมกับฝั่ง BSC เพื่อให้หน้าจอถือผลลัพธ์แบบเดียวกันได้
// (VerifiedToken = โทเคนที่ตรวจ decimals กับเชนแล้ว · SwapException = ข้อความ 2 ภาษา)
import 'bsc_swap_service.dart' show SwapException, VerifiedToken;

/// ค่าธรรมเนียมของพูล UniV2 — เข้าพูลทั้งก้อน ไม่ได้หักเพิ่มจากผู้ใช้
const double kTpixPoolFeePct = 0.3;

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
    rpc: 'https://rpc.tpix.online',
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
  });

  /// ค่าธรรมเนียมที่พูลกิน — แสดงให้ผู้ใช้เห็นเท่านั้น ไม่มีธุรกรรมแยก
  double get feeAmount => amountIn * (kTpixPoolFeePct / 100);

  double get feeRate => kTpixPoolFeePct;
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
  {"constant":false,"inputs":[{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactETHForTokens","outputs":[{"name":"amounts","type":"uint256[]"}],"payable":true,"type":"function"},
  {"constant":false,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactTokensForETH","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"},
  {"constant":false,"inputs":[{"name":"amountIn","type":"uint256"},{"name":"amountOutMin","type":"uint256"},{"name":"path","type":"address[]"},{"name":"to","type":"address"},{"name":"deadline","type":"uint256"}],"name":"swapExactTokensForTokens","outputs":[{"name":"amounts","type":"uint256[]"}],"type":"function"}
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

  TpixDexConfig _config = TpixDexConfig.notReady;
  DateTime? _configAt;
  Future<TpixDexConfig>? _configInFlight;

  /// decimals ที่อ่านจากเชนแล้ว — ค่าคงที่ตลอดอายุสัญญา
  final Map<String, int> _decimalsCache = {};

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

  List<String> get _rpcUrls => {
        _config.rpc,
        ChainConfig.tpix.rpcUrl,
        ...ChainConfig.tpix.fallbackRpcs,
      }.where((u) => u.isNotEmpty).toList();

  Future<T> _withClient<T>(Future<T> Function(Web3Client client) fn) async {
    Object? lastError;
    for (final url in _rpcUrls) {
      final client = Web3Client(url, http.Client());
      try {
        return await fn(client).timeout(const Duration(seconds: 15));
      } catch (e) {
        lastError = e;
      } finally {
        client.dispose();
      }
    }
    throw lastError ??
        const SwapException(
            'TPIX Chain unavailable.', 'เชื่อมต่อเชน TPIX ไม่ได้');
  }

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

  /// native TPIX ในฐานข้อมูลคือ 0x0 — ตรงนี้ถือว่าเป็น native ทุกกรณีที่ตรงกัน
  static bool isNative(String address) {
    final a = address.toLowerCase();
    return a.isEmpty ||
        a == nativeAddress ||
        a == '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
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
    } catch (_) {
      // อ่านเชนไม่ได้ = ไม่ยอมให้เทรดด้วยค่าที่เดา (fail-closed เหมือนฝั่ง BSC)
      throw const SwapException(
        'Could not verify the token on TPIX Chain.',
        'ตรวจสอบเหรียญบนเชน TPIX ไม่สำเร็จ',
      );
    }
  }

  /// ยอดคงเหลือบนเชน TPIX — คืน 0 เมื่ออ่านไม่ได้ (ใช้แสดงผลเท่านั้น)
  Future<double> getBalance({
    required String address,
    required String walletAddress,
    int decimals = 18,
  }) async {
    try {
      final owner = EthereumAddress.fromHex(walletAddress);
      if (isNative(address)) {
        final amount = await _withClient((client) => client.getBalance(owner));
        return fromWei(amount.getInWei, 18);
      }
      final contract = _erc20At(address);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('balanceOf'),
            params: [owner],
          ));
      final dec = _decimalsCache[address.toLowerCase()] ?? decimals;
      return fromWei(result.first as BigInt, dec);
    } catch (_) {
      return 0;
    }
  }

  // ── Quote ──

  /// ขอ quote จริงจาก router ของพูล
  ///
  /// [poolPrice] = ราคากลางของพูลจากเซิร์ฟเวอร์ (quote ต่อ 1 base) ใช้คิด price impact
  /// ไม่ส่งมาก็ได้ — จะได้ priceImpact = 0 แล้วให้ผู้เรียกตัดสินใจเอง
  Future<TpixDexQuote> getQuote({
    required VerifiedToken fromToken,
    required VerifiedToken toToken,
    required double amount,
    double slippage = 0.5,
  }) async {
    if (amount <= 0) {
      throw const SwapException('Enter an amount.', 'กรุณาระบุจำนวน');
    }

    final cfg = await _requireReady();
    final router = cfg.router!;
    final wtpix = cfg.wtpix!;

    final fromAddr = fromToken.native ? wtpix : fromToken.address;
    final toAddr = toToken.native ? wtpix : toToken.address;
    if (fromAddr.toLowerCase() == toAddr.toLowerCase()) {
      throw const SwapException(
          'Choose two different tokens.', 'เลือกเหรียญคนละตัว');
    }

    final amountInWei = toWei(amount, fromToken.decimals);
    if (amountInWei <= BigInt.zero) {
      throw const SwapException(
          'Amount is too small.', 'จำนวนน้อยเกินไป');
    }

    var path = <EthereumAddress>[
      EthereumAddress.fromHex(fromAddr),
      EthereumAddress.fromHex(toAddr),
    ];

    var amounts = await _getAmountsOut(router, amountInWei, path);

    // ไม่มีคู่ตรง → ลองวิ่งผ่าน WTPIX (เฉพาะ token ↔ token)
    if (amounts == null && !fromToken.native && !toToken.native) {
      path = [
        EthereumAddress.fromHex(fromAddr),
        EthereumAddress.fromHex(wtpix),
        EthereumAddress.fromHex(toAddr),
      ];
      amounts = await _getAmountsOut(router, amountInWei, path);
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

    // price impact จากราคากลางของพูล — วัดด้วย quote ก้อนจิ๋ว (1/1000 ของไม้)
    // เทียบกับราคาที่ไม้จริงได้ วิธีนี้ไม่ต้องอ่าน reserve เอง และตรงกับที่ผู้ใช้จ่ายจริง
    double priceImpact = 0;
    final probeWei = amountInWei ~/ BigInt.from(1000);
    if (probeWei > BigInt.zero) {
      final probe = await _getAmountsOut(router, probeWei, path);
      if (probe != null && probe.isNotEmpty && probe.last > BigInt.zero) {
        final spot = probe.last.toDouble() / probeWei.toDouble();
        final effective = rawOut.toDouble() / amountInWei.toDouble();
        if (spot > 0) {
          priceImpact = math.max(0, (1 - effective / spot) * 100);
        }
      }
    }

    return TpixDexQuote(
      fromToken: fromToken,
      toToken: toToken,
      amountIn: amount,
      netOutput: netOutput,
      minReceived: minReceived,
      slippage: slippage,
      priceImpact: double.parse(priceImpact.toStringAsFixed(4)),
      amountInWei: amountInWei,
      rawAmountOut: rawOut,
      path: path,
      router: router,
    );
  }

  Future<List<BigInt>?> _getAmountsOut(
      String router, BigInt amountIn, List<EthereumAddress> path) async {
    try {
      final contract = _routerAt(router);
      final result = await _withClient((client) => client.call(
            contract: contract,
            function: contract.function('getAmountsOut'),
            params: [amountIn, path],
          ));
      return (result.first as List).cast<BigInt>();
    } catch (e) {
      debugPrint('TPIX DEX getAmountsOut failed: ${e.runtimeType}');
      return null;
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
    } catch (_) {
      return false; // อ่านไม่ได้ = บังคับ approve (ปลอดภัยกว่าเดาว่าพอ)
    }
  }

  /// Calldata approve router แบบไม่จำกัด (pattern เดียวกับเว็บและฝั่ง BSC)
  Future<Uint8List> approveCalldata(String tokenAddress) async {
    final cfg = await _requireReady();
    final contract = _erc20At(tokenAddress);
    final maxUint = (BigInt.one << 256) - BigInt.one;
    return contract.function('approve').encodeCall([
      EthereumAddress.fromHex(cfg.router!),
      maxUint,
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
