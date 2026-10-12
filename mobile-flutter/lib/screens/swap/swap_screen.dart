/// TPIX TRADE — Swap Screen (Luxury Dark / Gilded Metal)
/// Token-to-token swap จริง เลือกเครือข่ายเองได้สองทาง:
///
///  - BSC (56) ผ่าน PancakeSwap V2 — เส้นทางเดิมทุกขั้น: quote จริงจาก router
///    (debounced), fee หักจากฝั่งจ่ายแบบ reserve, ยอดคงเหลืออ่านจาก BSC RPC ตรง
///  - TPIX Chain (4289) ผ่าน TPIX DEX — ใช้ [TpixDexService] ตัวเดียวกับหน้าเทรด
///    (ที่อยู่สัญญาจาก /dex/config แบบ fail-closed · decimals อ่านจากเชน · เพดาน
///    price impact 10% · approve เท่ายอดไม้ · บันทึกไม้กับเซิร์ฟเวอร์ใน service)
///    เปิดเฉพาะเมื่อเซิร์ฟเวอร์ยืนยันว่า DEX พร้อม — ไม่พร้อม = ปุ่มเทาพร้อมป้าย "เร็ว ๆ นี้"
///
/// กด Swap แล้ว settle บนเชนจริง เหรียญเข้ากระเป๋าผู้ใช้ (มีลิงก์ดู tx บน explorer)
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../models/bsc_trade_tokens.dart';
import '../../models/chain_config.dart';
import '../../providers/accent_provider.dart';
import '../../providers/config_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../services/bsc_swap_service.dart';
import '../../services/swap_network.dart';
import '../../services/token_list_service.dart';
import '../../services/tpix_dex_service.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';

class SwapScreen extends StatefulWidget {
  const SwapScreen({super.key});

  @override
  State<SwapScreen> createState() => _SwapScreenState();
}

class _SwapScreenState extends State<SwapScreen> {
  final _payController = TextEditingController();
  final _swapService = BscSwapService();
  final _dexService = TpixDexService();

  static const _approveRejected =
      SwapException('Approval rejected.', 'การ approve ถูกปฏิเสธ');
  static const _swapFailed =
      SwapException('Swap failed — try again.', 'สลับไม่สำเร็จ ลองใหม่');

  // ── เครือข่าย ──
  // เริ่มที่ BSC = พฤติกรรมเดิมทุกอย่าง · TPIX Chain ต้องให้ผู้ใช้เลือกเองเท่านั้น
  SwapNetwork _network = SwapNetwork.bsc;
  // ถามทะเบียน DEX จากเซิร์ฟเวอร์รอบแรกเสร็จหรือยัง (ใช้แค่ข้อความ "กำลังตรวจสอบ")
  bool _dexConfigLoaded = false;
  // กันตั้ง post-frame ซ้ำระหว่างรอถอยกลับ BSC/รีเซ็ตเหรียญ
  bool _tpixFixScheduled = false;

  // ── เหรียญที่เลือก ──
  // BSC: ชื่อเหรียญใน BSC registry เท่านั้น (ทะเบียนเดียวกับหน้า Trade/เว็บ)
  String _fromToken = 'BNB';
  String _toToken = 'USDT';
  // TPIX: เก็บเป็น "ที่อยู่" ไม่ใช่ชื่อ — ชื่อปลอมได้ ที่อยู่ปลอมไม่ได้
  String? _tpixFromKey;
  String? _tpixToKey;
  // รายการเหรียญบนเชน TPIX — คำนวณใหม่ทุก build จากคู่ของเซิร์ฟเวอร์ (ดู SwapNetworkRules)
  List<TpixSwapToken> _tpixTokens = const [];

  double _slippage = 0.5; // %

  // ── Quote ──
  BscSwapQuote? _routerQuote; // BSC (PancakeSwap)
  TpixDexQuote? _dexQuote; // TPIX Chain (TPIX DEX)
  Map<String, dynamic>? _quote; // มุมมองแบบ map สำหรับ details card (ใช้ร่วมสองเครือข่าย)
  // quote ที่แสดงอยู่เป็นของบริบทไหน (เครือข่าย|เหรียญ|จำนวน|slippage)
  // — ปุ่มสลับกดได้เฉพาะเมื่อตรงกับบริบทปัจจุบันเป๊ะ ๆ
  String? _quotedFor;
  bool _quoting = false;
  String? _quoteError;
  int _quoteSeq = 0; // ทุกการเปลี่ยนบริบทขยับเลขนี้ → ผลของคำขอเก่าที่กลับมาทีหลังถูกทิ้ง
  Timer? _debounce;
  String _lastAmountText = '';

  // ── Execution ──
  bool _executing = false;
  bool _reviewOpen = false; // กดปุ่มสลับรัว ๆ = เปิดหน้ายืนยันแค่ใบเดียว

  // ยอดคงเหลือบน BSC ของเหรียญที่เลือก (อ่านจาก RPC ตรง — ไม่ใช่ portfolio
  // ของ active chain เพราะโหมดนี้ทำงานบน BSC เสมอ)
  final Map<String, double> _bscBalances = {};
  String? _balanceWalletKey;

  // ยอดคงเหลือบนเชน TPIX (หน่วยเล็กสุด ตรงตัว) — key = ที่อยู่เหรียญ
  // เป็นของกระเป๋า [_tpixBalancesWallet] เท่านั้น ห้ามเอาไปแสดงกับกระเป๋าอื่น
  final Map<String, BigInt> _tpixBalancesWei = {};
  // decimals ที่ยืนยันกับเชนแล้ว — ไม่มีในนี้ = ยังไม่ยอมเติม "สูงสุด" ให้
  final Map<String, int> _tpixDecimals = {};
  String? _tpixBalancesWallet;

  @override
  void initState() {
    super.initState();
    // Seed slippage from the user's saved wallet preference.
    final wallet = context.read<WalletProvider>();
    _slippage = wallet.slippage;
    _payController.addListener(_onAmountTextChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _loadBalances();
      // คู่เทรดบนเชน TPIX มาจาก /market/pairs — เข้าหน้านี้ห่างจาก splash นานก็ขอใหม่
      context.read<ConfigProvider>().refreshIfStale();
      _refreshDexConfig();
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _payController.removeListener(_onAmountTextChanged);
    _payController.dispose();
    super.dispose();
  }

  /// ถามทะเบียนสัญญา DEX จากเซิร์ฟเวอร์ (แคช 60 วิใน service · ล้มเหลว = ยังไม่พร้อม)
  Future<void> _refreshDexConfig() async {
    await _dexService.loadConfig();
    if (!mounted) return;
    setState(() => _dexConfigLoaded = true);
  }

  // ── Helpers ──

  bool get _isTpix => _network == SwapNetwork.tpix;

  TpixSwapToken? _tpixByKey(String? key) {
    if (key == null) return null;
    for (final t in _tpixTokens) {
      if (t.key == key) return t;
    }
    return null;
  }

  TpixSwapToken? _tpixBySymbol(String symbol) {
    for (final t in _tpixTokens) {
      if (t.symbol == symbol) return t;
    }
    return null;
  }

  String get _fromSymbol =>
      _isTpix ? (_tpixByKey(_tpixFromKey)?.symbol ?? '—') : _fromToken;

  String get _toSymbol =>
      _isTpix ? (_tpixByKey(_tpixToKey)?.symbol ?? '—') : _toToken;

  /// บริบทของ quote — อะไรในนี้เปลี่ยน quote เดิมใช้ส่งไม่ได้แล้ว
  String _quoteContext() => [
        _network.name,
        _isTpix ? (_tpixFromKey ?? '') : _fromToken,
        _isTpix ? (_tpixToKey ?? '') : _toToken,
        _payController.text.trim(),
        _slippage.toStringAsFixed(4),
      ].join('|');

  /// คู่ที่เลือกในโหมด TPIX ใช้ได้ไหม (มีในรายการ · คนละสินทรัพย์ในสายตา router)
  bool _tpixSelectionOk() {
    final from = _tpixByKey(_tpixFromKey);
    final to = _tpixByKey(_tpixToKey);
    return from != null &&
        to != null &&
        !SwapNetworkRules.sameDexAsset(from, to, _dexService.lastConfig);
  }

  // ── Token universe ──

  /// เหรียญที่ swap จริงบน BSC ได้ = registry เดียวกับหน้า Trade/เว็บเท่านั้น
  List<String> _bscTokenUniverse() {
    final list = kBscTradeTokens.keys.toList()..sort();
    return list;
  }

  /// รายการเหรียญบนเชน TPIX จากคู่ของเซิร์ฟเวอร์ — กติกาเดียวกับหน้าเทรด (fail-closed)
  void _syncTpixTokens(ConfigProvider config, MarketProvider market) {
    _tpixTokens = SwapNetworkRules.buildTpixTokens(
      dex: _dexService.lastConfig,
      serverPairs: config.pairs,
      verifiedPairs: market.verifiedDexPairs,
      approvedLogo: TokenListService().logoFor,
    );
  }

  /// โหมด TPIX แต่ DEX ใช้ไม่ได้แล้ว / เหรียญที่เลือกหายจากรายการ → แก้หลังเฟรม
  /// (เรียกจาก build — setState ตรงนี้ไม่ได้)
  void _scheduleTpixFixIfNeeded(bool tpixAvailable) {
    if (!_isTpix || _executing || _tpixFixScheduled) return;
    if (tpixAvailable && _tpixSelectionOk()) return;
    _tpixFixScheduled = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _tpixFixScheduled = false;
      if (!mounted || !_isTpix || _executing) return;
      final available = SwapNetworkRules.tpixModeAvailable(
          _dexService.lastConfig, _tpixTokens);
      if (!available) {
        // ทะเบียนบอกไม่พร้อมแล้ว — ห้ามค้างโหมดที่ส่งไม่ได้ กลับไป BSC แล้วบอกผู้ใช้ตรง ๆ
        final th = context.read<LocaleProvider>().isThai;
        setState(() => _network = SwapNetwork.bsc);
        _afterContextChange();
        _snack(th
            ? 'TPIX DEX ใช้ไม่ได้ชั่วคราว — กลับมาสลับบน BSC'
            : 'TPIX DEX is unavailable right now — switched back to BSC');
        return;
      }
      if (!_tpixSelectionOk()) {
        // เหรียญที่เลือกถูกเลิกลิสต์/ชื่อกำกวม → กลับไปคู่เริ่มต้น
        final d = SwapNetworkRules.defaultTpixSelection(_tpixTokens);
        setState(() {
          _tpixFromKey = d.from;
          _tpixToKey = d.to;
        });
        _afterContextChange();
      }
    });
  }

  /// เปลี่ยนเครือข่าย/เหรียญ/ทิศแล้ว: ทิ้ง quote เดิม · ขอราคาใหม่ · โหลดยอดของเหรียญชุดใหม่
  void _afterContextChange() {
    _invalidateQuote();
    _requote();
    _loadBalances();
  }

  // ── Balances ──

  double? _balanceOf(WalletProvider wallet, String symbol) {
    if (_isTpix) {
      final t = _tpixBySymbol(symbol);
      return t == null ? null : _tpixBalance(wallet, t);
    }
    return _bscBalances[symbol.toUpperCase()];
  }

  /// ยอดบนเชน TPIX ของกระเป๋าที่เชื่อมอยู่ตอนนี้ — null = ยังไม่รู้ (ห้ามแปลเป็น 0)
  BigInt? _tpixBalanceWei(WalletProvider wallet, TpixSwapToken t) {
    final addr = wallet.address?.toLowerCase();
    if (addr == null || addr != _tpixBalancesWallet) return null;
    return _tpixBalancesWei[t.key];
  }

  double? _tpixBalance(WalletProvider wallet, TpixSwapToken t) {
    final wei = _tpixBalanceWei(wallet, t);
    if (wei == null) return null;
    return TpixDexService.fromWei(wei, _tpixDecimals[t.key] ?? t.decimals);
  }

  void _loadBalances() {
    if (!mounted) return;
    if (_isTpix) {
      _loadTpixBalances();
    } else {
      _loadBscBalances();
    }
  }

  /// โหลดยอดจาก BSC ของเหรียญที่เลือกอยู่ (from + to)
  Future<void> _loadBscBalances() async {
    final wallet = context.read<WalletProvider>();
    if (!wallet.isConnected || wallet.address == null) return;
    final address = wallet.address!;
    final symbols = {_fromToken, _toToken};
    final results = await Future.wait(
        symbols.map((s) => _swapService.getBalance(s, address)));
    if (!mounted) return;
    // สลับกระเป๋าระหว่างรอ — ยอดชุดนี้เป็นของกระเป๋าเก่า ทิ้งไป
    if (context.read<WalletProvider>().address != address) return;
    setState(() {
      var i = 0;
      for (final s in symbols) {
        _bscBalances[s.toUpperCase()] = results[i++];
      }
    });
  }

  /// โหลดยอดบนเชน TPIX ของเหรียญที่เลือกอยู่ — decimals ยืนยันกับเชนไปพร้อมกัน
  Future<void> _loadTpixBalances() async {
    final wallet = context.read<WalletProvider>();
    if (!wallet.isConnected || wallet.address == null) return;
    final address = wallet.address!.toLowerCase();
    final tokens = <String, TpixSwapToken>{};
    for (final t in [_tpixByKey(_tpixFromKey), _tpixByKey(_tpixToKey)]) {
      if (t != null) tokens[t.key] = t;
    }
    if (tokens.isEmpty) return;

    final results = await Future.wait(tokens.values.map((t) async {
      int? decimals;
      try {
        decimals = (await _dexService.verifyToken(
          symbol: t.symbol,
          address: t.address,
          fallbackDecimals: t.decimals,
        ))
            .decimals;
      } catch (_) {
        // ตรวจกับเชนไม่ได้ = ยังไม่ให้กด "สูงสุด" (ยอดแสดงด้วยค่าจากเซิร์ฟเวอร์ไปก่อน)
      }
      final wei = await _dexService.getBalanceWei(
          address: t.address, walletAddress: address);
      return (key: t.key, decimals: decimals, wei: wei);
    }));

    if (!mounted) return;
    // สลับกระเป๋าระหว่างรอ — ยอดชุดนี้เป็นของกระเป๋าเก่า ทิ้งไป
    if (context.read<WalletProvider>().address?.toLowerCase() != address) {
      return;
    }
    setState(() {
      if (_tpixBalancesWallet != address) {
        _tpixBalancesWei.clear();
        _tpixBalancesWallet = address;
      }
      for (final r in results) {
        final dec = r.decimals;
        if (dec != null) _tpixDecimals[r.key] = dec;
        // อ่านพลาด (null) = คงยอดเดิมของกระเป๋านี้ไว้ — ห้ามแสดงเป็น 0 (คนจะตกใจว่าเงินหาย)
        final wei = r.wei;
        if (wei != null) _tpixBalancesWei[r.key] = wei;
      }
    });
  }

  /// โหลดยอดใหม่เมื่อ wallet เปลี่ยน (เรียกจาก build — กันซ้ำด้วย key)
  void _maybeReloadBalances(WalletProvider wallet) {
    final key = wallet.address ?? '';
    if (key == _balanceWalletKey) return;
    _balanceWalletKey = key;
    // ยอดของกระเป๋าก่อนหน้าห้ามค้างจอ (ฝั่ง TPIX กันด้วย _tpixBalancesWallet อยู่แล้ว)
    _bscBalances.clear();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadBalances());
  }

  // ── Quote fetching (debounced) ──

  void _onAmountTextChanged() {
    final text = _payController.text;
    // เลื่อนเคอร์เซอร์อย่างเดียว (controller แจ้งเหมือนกัน) — ไม่ต้องขอราคาใหม่
    if (text == _lastAmountText) return;
    _lastAmountText = text;
    _requote();
  }

  /// ขอราคาใหม่แบบ debounce — ผลของคำขอที่ค้างอยู่ทั้งหมดถูกทิ้ง
  ///
  /// ตัวเลขเดิมยังโชว์อยู่ระหว่างรอ (ไม่กระพริบ) แต่ปุ่มสลับปิดจนกว่า quote ของจำนวนใหม่จะมา
  /// — กันกรณีพิมพ์จำนวนใหม่แล้วกดทันที หน้ายืนยันจะโชว์ "ได้รับ" ของจำนวนเก่า
  void _requote() {
    if (!mounted) return;
    _debounce?.cancel();
    _quoteSeq++;
    final amount = double.tryParse(_payController.text.trim());
    if (amount == null || amount <= 0) {
      setState(_clearQuoteState);
      return;
    }
    setState(() {
      _quoting = true;
      _quoteError = null;
    });
    _debounce = Timer(const Duration(milliseconds: 550), _fetchQuote);
  }

  /// ล้าง quote ทันที (เปลี่ยนเครือข่าย/เหรียญ/ทิศ/slippage) — คำขอที่ค้างอยู่ถูกทิ้ง
  void _invalidateQuote() {
    _debounce?.cancel();
    _quoteSeq++;
    if (!mounted) return;
    setState(_clearQuoteState);
  }

  void _clearQuoteState() {
    _quote = null;
    _routerQuote = null;
    _dexQuote = null;
    _quotedFor = null;
    _quoteError = null;
    _quoting = false;
  }

  Future<void> _fetchQuote() async {
    if (!mounted) return;
    final text = _payController.text.trim();
    final amount = double.tryParse(text);
    if (amount == null || amount <= 0) return;
    // ภาษาจับไว้ก่อน await — ห้ามแตะ context ข้าม async gap
    final th = context.read<LocaleProvider>().isThai;
    final ctx = _quoteContext();
    final seq = ++_quoteSeq;

    if (_isTpix) {
      await _fetchTpixQuote(seq, ctx, text, amount, th);
    } else {
      await _fetchBscQuote(seq, ctx, amount, th);
    }
  }

  /// Quote จริงจาก PancakeSwap router (ตรวจ token กับ on-chain ในตัว)
  Future<void> _fetchBscQuote(
      int seq, String ctx, double amount, bool th) async {
    final from = _fromToken;
    final to = _toToken;
    if (from == to) {
      setState(_clearQuoteState);
      return;
    }

    setState(() {
      _quoting = true;
      _quoteError = null;
    });

    BscSwapQuote? quote;
    String? error;
    try {
      quote = await _swapService.getQuote(
        fromSymbol: from,
        toSymbol: to,
        amount: amount,
        slippageOverride: _slippage,
      );
    } on SwapException catch (e) {
      error = e.message(th);
    } catch (_) {
      error = null; // ใช้ข้อความ default ด้านล่าง
    }

    // Guard: a newer request superseded this one, or the screen is gone.
    if (!mounted || seq != _quoteSeq) return;

    setState(() {
      _quoting = false;
      _routerQuote = quote;
      _dexQuote = null;
      _quotedFor = quote == null ? null : ctx;
      // มุมมอง map สำหรับ details card (field ตรงกับ reader เดิม)
      _quote = quote == null
          ? null
          : {
              'amount_out': quote.netOutput,
              'rate': quote.amountIn > 0
                  ? quote.netOutput / quote.amountIn
                  : null,
              'min_received': quote.minReceived,
              'slippage': quote.slippage,
              // เส้นทาง swap จริง — 3 ขาแปลว่าวิ่งผ่าน WBNB
              'route':
                  quote.path.length == 3 ? [from, 'WBNB', to] : [from, to],
            };
      _quoteError = quote == null
          ? (error ??
              (th
                  ? 'ดึงราคาไม่สำเร็จ ลองอีกครั้ง'
                  : 'Could not fetch quote. Try again.'))
          : null;
    });
  }

  /// Quote จริงจากพูลของ TPIX DEX — ทางเดียวกับหน้าเทรด (ไม่มีคณิตสวอปในหน้าจอ)
  Future<void> _fetchTpixQuote(
      int seq, String ctx, String text, double amount, bool th) async {
    final from = _tpixByKey(_tpixFromKey);
    final to = _tpixByKey(_tpixToKey);
    if (from == null || to == null || !_tpixSelectionOk()) {
      setState(_clearQuoteState);
      return;
    }

    setState(() {
      _quoting = true;
      _quoteError = null;
    });

    try {
      final quote = await _quoteTpix(from, to, text, amount, _slippage);
      if (!mounted || seq != _quoteSeq) return;
      setState(() {
        _quoting = false;
        _dexQuote = quote;
        _routerQuote = null;
        _quotedFor = ctx;
        _quoteError = null;
        _quote = _tpixQuoteView(quote, from, to);
      });
    } on SwapException catch (e) {
      if (!mounted || seq != _quoteSeq) return;
      setState(() {
        _clearQuoteState();
        // บอกเหตุผลก่อนกด (ไม่มีสภาพคล่อง / เชนติดต่อไม่ได้ / DEX ยังไม่พร้อม)
        _quoteError = e.message(th);
      });
    } catch (_) {
      if (!mounted || seq != _quoteSeq) return;
      setState(() {
        _clearQuoteState();
        _quoteError = th
            ? 'ดึงราคาไม่สำเร็จ ลองอีกครั้ง'
            : 'Could not fetch quote. Try again.';
      });
    }
  }

  /// มุมมอง map ของ quote บนเชน TPIX สำหรับ details card (field ตรงกับ reader เดิม)
  Map<String, dynamic> _tpixQuoteView(
          TpixDexQuote quote, TpixSwapToken from, TpixSwapToken to) =>
      {
        'amount_out': quote.netOutput,
        'rate': quote.amountIn > 0 ? quote.netOutput / quote.amountIn : null,
        'min_received': quote.minReceived,
        'slippage': quote.slippage,
        // 3 ขา = เหรียญลูก ↔ เหรียญลูก วิ่งผ่าน WTPIX (router จัดการให้)
        'route': quote.path.length == 3
            ? [from.symbol, 'WTPIX', to.symbol]
            : [from.symbol, to.symbol],
      };

  /// โทเคนสองฝั่งบนเชน TPIX — decimals ยืนยันกับเชนก่อนใช้เสมอ (ค่าจาก API เป็นแค่ค่าตั้งต้น)
  Future<(VerifiedToken, VerifiedToken)> _verifyTpixPair(
      TpixSwapToken from, TpixSwapToken to) async {
    final f = await _dexService.verifyToken(
      symbol: from.symbol,
      address: from.address,
      fallbackDecimals: from.decimals,
    );
    final t = await _dexService.verifyToken(
      symbol: to.symbol,
      address: to.address,
      fallbackDecimals: to.decimals,
    );
    return (f, t);
  }

  /// Quote แบบ "จ่ายเท่านี้" (exact-in) ผ่าน [TpixDexService.getQuote]
  /// จำนวนแปลงจากข้อความตรงตัว ([TpixDexService.parseUnits]) — ไม่ผ่าน double
  /// (ยอดหลักร้อยล้านเหรียญ double ปัดเกินยอดจริงได้ → กด "สูงสุด" แล้วเจอยอดไม่พอ)
  /// [slippage] จับมาตั้งแต่ก่อน await — แก้ค่าระหว่างรอ RPC ต้องไม่ไปปนกับไม้ที่วิ่งอยู่
  Future<TpixDexQuote> _quoteTpix(TpixSwapToken from, TpixSwapToken to,
      String text, double amount, double slippage) async {
    final (fromTok, toTok) = await _verifyTpixPair(from, to);
    final amountWei = TpixDexService.parseUnits(text, fromTok.decimals);
    if (amountWei == null || amountWei <= BigInt.zero) {
      throw const SwapException(
          'Enter a valid amount.', 'กรอกจำนวนให้ถูกต้อง');
    }
    return _dexService.getQuote(
      fromToken: fromTok,
      toToken: toTok,
      amount: amount,
      amountInWei: amountWei,
      slippage: slippage,
    );
  }

  // ── Network switch ──

  void _selectNetwork(SwapNetwork next, bool tpixAvailable) {
    // กำลังส่งธุรกรรมอยู่ = ห้ามสลับเครือข่าย (ไม้ที่วิ่งอยู่ผูกกับเครือข่ายเดิม)
    if (_executing || next == _network) return;
    final th = context.read<LocaleProvider>().isThai;
    if (next == SwapNetwork.tpix && !tpixAvailable) {
      _snack(th
          ? 'สลับบนเชน TPIX เร็ว ๆ นี้ — ตอนนี้ใช้ BSC ได้ตามปกติ'
          : 'TPIX Chain swaps are coming soon — BSC works as usual');
      _refreshDexConfig(); // ลองถามทะเบียนอีกรอบ (แคช 60 วิ ไม่ถล่มเซิร์ฟเวอร์)
      return;
    }
    setState(() {
      _network = next;
      if (next == SwapNetwork.tpix && !_tpixSelectionOk()) {
        final d = SwapNetworkRules.defaultTpixSelection(_tpixTokens);
        _tpixFromKey = d.from;
        _tpixToKey = d.to;
      }
    });
    _afterContextChange();
  }

  // ── Direction flip ──

  void _flipDirection() {
    if (_executing) return;
    setState(() {
      if (_isTpix) {
        final wasFrom = _tpixFromKey;
        _tpixFromKey = _tpixToKey;
        _tpixToKey = wasFrom;
      } else {
        final wasFrom = _fromToken;
        _fromToken = _toToken;
        _toToken = wasFrom;
      }
    });
    _afterContextChange();
  }

  // ── Token picker ──

  Future<void> _pickToken({required bool isFrom}) async {
    if (_executing) return;
    final wallet = context.read<WalletProvider>();
    final locale = context.read<LocaleProvider>();
    final network = _network;
    final tpix = network == SwapNetwork.tpix;
    final tokens =
        tpix ? _tpixTokens.map((t) => t.symbol).toList() : _bscTokenUniverse();

    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _TokenPickerSheet(
        tokens: tokens,
        balanceOf: (s) => _balanceOf(wallet, s),
        selected: isFrom ? _fromSymbol : _toSymbol,
        disabled: isFrom ? _toSymbol : _fromSymbol,
        locale: locale,
        logoOf: tpix ? (s) => _tpixBySymbol(s)?.logo : null,
        chainTokenOf:
            tpix ? (s) => !(_tpixBySymbol(s)?.verifiedMajor ?? false) : null,
      ),
    );

    if (picked == null || !mounted) return;
    // เครือข่ายเปลี่ยน/เริ่มส่งธุรกรรมระหว่างเปิดรายการ — ผลที่เลือกเป็นของบริบทเก่า ทิ้งไป
    if (network != _network || _executing) return;

    if (tpix) {
      final token = _tpixBySymbol(picked);
      if (token == null) return;
      setState(() {
        if (isFrom) {
          _tpixFromKey = token.key;
        } else {
          _tpixToKey = token.key;
        }
      });
    } else {
      setState(() {
        if (isFrom) {
          _fromToken = picked;
        } else {
          _toToken = picked;
        }
      });
    }
    _afterContextChange();
  }

  // ── Slippage dialog ──

  Future<void> _openSlippageDialog() async {
    final locale = context.read<LocaleProvider>();
    final picked = await showDialog<double>(
      context: context,
      builder: (_) => _SlippageDialog(current: _slippage, locale: locale),
    );
    if (picked == null || !mounted) return;
    setState(() => _slippage = picked);
    _invalidateQuote();
    _requote();
  }

  // ── Review + execute (เทรดจริงบนเชน) ──

  Future<void> _reviewSwap() async {
    if (_reviewOpen || _executing) return;
    final locale = context.read<LocaleProvider>();
    final th = locale.isThai;
    final amount = double.tryParse(_payController.text.trim());
    if (amount == null || amount <= 0) {
      _snack(th ? 'กรอกจำนวนให้ถูกต้อง' : 'Enter a valid amount');
      return;
    }
    if (_quoting || _quotedFor != _quoteContext()) {
      _snack(th ? 'รอราคาก่อน' : 'Wait for the quote');
      return;
    }

    final network = _network;
    final fromSym = _fromSymbol;
    final toSym = _toSymbol;
    final double receive;
    final String feeText;
    final String minText;
    final String networkLabel;
    String? impactText;
    Color? impactColor;

    if (network == SwapNetwork.tpix) {
      final q = _dexQuote;
      if (q == null) {
        _snack(th ? 'รอราคาก่อน' : 'Wait for the quote');
        return;
      }
      if (q.exceedsImpactLimit) {
        _snack(_impactBlockedMessage(q, th, fromSym));
        return;
      }
      receive = q.netOutput;
      feeText =
          '${_trimNum(q.feeAmount)} $fromSym (${_trimNum(q.feeRate)}% ${th ? 'ในพูล' : 'in pool'})';
      minText = '${_trimNum(q.minReceived)} $toSym';
      networkLabel = 'TPIX Chain · TPIX DEX';
      impactText = _impactText(q.priceImpact);
      impactColor = _impactColor(q.priceImpact);
    } else {
      final q = _routerQuote;
      if (q == null) {
        _snack(th ? 'รอราคาก่อน' : 'Wait for the quote');
        return;
      }
      receive = q.netOutput;
      feeText = '${_trimNum(q.feeAmount)} $fromSym (${_trimNum(q.feeRate)}%)';
      minText = '${_trimNum(q.minReceived)} $toSym';
      networkLabel = 'BSC · PancakeSwap';
    }

    _reviewOpen = true;
    var confirmed = false;
    try {
      await showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (sheetCtx) => _ReviewSheet(
          fromToken: fromSym,
          toToken: toSym,
          payAmount: amount,
          receiveAmount: receive,
          feeText: feeText,
          minReceivedText: minText,
          networkLabel: networkLabel,
          priceImpactText: impactText,
          priceImpactColor: impactColor,
          fromLogo: _tpixLogoFor(network, fromSym),
          toLogo: _tpixLogoFor(network, toSym),
          fromChainToken: _chainTokenFor(network, fromSym),
          toChainToken: _chainTokenFor(network, toSym),
          locale: locale,
          onConfirm: () {
            // กดยืนยันรัว ๆ = ส่งครั้งเดียว และปิดเฉพาะหน้ายืนยัน (ไม่ pop หน้าจอข้างใต้)
            if (confirmed) return;
            confirmed = true;
            Navigator.pop(sheetCtx);
          },
        ),
      );
    } finally {
      _reviewOpen = false;
    }
    if (!mounted || !confirmed) return;
    await _executeSwap(network);
  }

  /// ส่งไม้ตามเครือข่ายที่ผู้ใช้ยืนยันในหน้ารีวิว
  Future<void> _executeSwap(SwapNetwork network) async {
    if (_executing) return;
    // เครือข่ายถูกเปลี่ยนหลังหน้ายืนยัน — ห้ามส่งไม้ที่ผู้ใช้ไม่ได้ยืนยัน
    if (network != _network) return;
    final wallet = context.read<WalletProvider>();
    final locale = context.read<LocaleProvider>();
    if (!wallet.isConnected || wallet.address == null) return;

    if (network == SwapNetwork.tpix) {
      await _executeTpixSwap(wallet, locale);
    } else {
      await _executeBscSwap(wallet, locale);
    }
  }

  /// Execute swap จริงบน BSC — quote ใหม่สดก่อนส่งเสมอ (กันราคาค้างจอ)
  /// แล้ววิ่ง flow เดียวกับหน้า Trade: approve → swap → เก็บ fee → บันทึก
  /// (chain 56 ตายตัวผ่าน sendBscTransaction — ไม่ขึ้นกับเชนปริยายของกระเป๋า)
  Future<void> _executeBscSwap(
      WalletProvider wallet, LocaleProvider locale) async {
    final amount = double.tryParse(_payController.text.trim());
    if (amount == null || amount <= 0) return;
    // จับค่าไว้ตอนเริ่ม — ข้อความผลลัพธ์ต้องเป็นของไม้นี้ ไม่ใช่ของที่จอแสดงตอนจบ
    final fromSym = _fromToken;
    final toSym = _toToken;
    final address = wallet.address!;

    setState(() => _executing = true);
    try {
      // 1) Quote สดใหม่ ณ วินาทีส่ง — minOut คิดจากราคาปัจจุบันจริง
      final quote = await _swapService.getQuote(
        fromSymbol: fromSym,
        toSymbol: toSym,
        amount: amount,
        slippageOverride: _slippage,
      );

      // 2) Approve router ถ้า allowance ไม่พอ (ข้ามได้ถ้าจ่ายด้วย BNB)
      if (!quote.fromToken.native) {
        final hasAllowance = await _swapService.hasAllowance(
          quote.fromToken,
          address,
          quote.amountInSwapWei,
        );
        if (!hasAllowance) {
          _ensureSameWallet(wallet, address);
          if (mounted) {
            _snack(locale.isThai
                ? 'กำลังขอ approve $fromSym — ยืนยันในกระเป๋า'
                : 'Approving $fromSym — confirm in your wallet');
          }
          final approveHash = await wallet.sendBscTransaction(
            to: quote.fromToken.address,
            data: _swapService.approveCalldata(quote.amountInSwapWei),
            summary: 'Approve $fromSym for PancakeSwap router',
          );
          if (approveHash == null) throw _approveRejected;
          final approved = await _swapService.waitConfirmed(approveHash);
          if (!approved) {
            throw const SwapException(
                'Approval not confirmed — try again.',
                'การ approve ยังไม่ยืนยัน ลองใหม่อีกครั้ง');
          }
        }
      }

      // 3) Swap จริง — service เก็บ fee + บันทึก backend ให้ครบ
      // ห่อ sender เพื่อแนบ summary ให้ TPIX Wallet โชว์ตอนยืนยัน (linked)
      final actionText = 'Swap $fromSym → $toSym — TPIX Trade';
      final result = await _swapService.executeMarketSwap(
        quote: quote,
        walletAddress: address,
        sendTx: ({required String to, Uint8List? data, BigInt? value}) {
          // กระเป๋าต้องยังเป็นใบเดียวกับตอนกดยืนยัน — ไม่งั้นใบใหม่จ่ายแต่ใบเก่าได้ของ
          _ensureSameWallet(wallet, address);
          return wallet.sendBscTransaction(
            to: to,
            data: data,
            value: value,
            summary: actionText,
          );
        },
      );

      if (!mounted) return;

      _payController.clear();
      _invalidateQuote();
      final received = '${_trimNum(quote.netOutput)} $toSym';
      _snack(
        result.confirmed
            ? (locale.isThai
                ? 'สลับสำเร็จ ≈ $received'
                : 'Swapped ≈ $received')
            : (locale.isThai
                ? 'ส่งธุรกรรมแล้ว กำลังรอยืนยันบนเชน'
                : 'Transaction sent — awaiting confirmation'),
        actionLabel: locale.isThai ? 'ดู tx' : 'View tx',
        onAction: () => launchUrl(
          Uri.parse(result.explorerUrl),
          mode: LaunchMode.externalApplication,
        ),
      );
      _loadBalances();
      wallet.loadPortfolio();
    } on SwapException catch (e) {
      if (mounted) _snack(e.message(locale.isThai));
    } catch (_) {
      if (mounted) {
        _snack(SwapNetworkRules.walletErrorText(
            wallet.error, locale.isThai, _swapFailed));
      }
    } finally {
      if (mounted) setState(() => _executing = false);
    }
  }

  /// Swap จริงบนเชน TPIX ผ่านพูลของ TPIX DEX — ลำดับเดียวกับหน้าเทรด
  ///
  /// ต่างจากฝั่ง BSC สองเรื่อง:
  ///   1. ค่าธรรมเนียม 0.3% อยู่ในพูลแล้ว ไม่มีธุรกรรมโอนค่าธรรมเนียมใบที่สอง
  ///   2. ใช้ price impact กันไม้ใหญ่เกินพูล (เพดาน 10% เท่ากับเว็บ/หน้าเทรด)
  /// ส่งทุกธุรกรรมด้วย chain id ของทะเบียน DEX (4289) ระบุชัด — ไม่ใช้เชนปริยายของกระเป๋า
  Future<void> _executeTpixSwap(
      WalletProvider wallet, LocaleProvider locale) async {
    final th = locale.isThai;
    final fromTk = _tpixByKey(_tpixFromKey);
    final toTk = _tpixByKey(_tpixToKey);
    final amountText = _payController.text.trim();
    final amount = double.tryParse(amountText);
    if (fromTk == null || toTk == null || amount == null || amount <= 0) {
      return;
    }
    final chainId =
        SwapNetworkRules.chainIdFor(SwapNetwork.tpix, _dexService.lastConfig);
    if (chainId == null) {
      _snack(th
          ? 'TPIX DEX ยังไม่พร้อม ลองใหม่ภายหลัง'
          : 'TPIX DEX is not ready — try again later');
      return;
    }
    // จับค่าไว้ตอนเริ่ม — ผู้ใช้อาจสลับกระเป๋า/แก้จำนวนระหว่างที่ไม้นี้ยังวิ่งอยู่
    final address = wallet.address!;
    final ctx = _quoteContext();
    final slippage = _slippage;

    setState(() => _executing = true);
    String? sendError;
    try {
      // 1) Quote สดใหม่ ณ วินาทีส่ง — minOut คิดจาก slippage กับราคาพูลตอนนี้จริง
      final quote =
          await _quoteTpix(fromTk, toTk, amountText, amount, slippage);

      // 2) เพดาน price impact เดียวกับเว็บ/หน้าเทรด — บอกจำนวนสูงสุดที่ส่งได้ด้วย
      if (quote.exceedsImpactLimit) {
        if (mounted && ctx == _quoteContext()) {
          setState(() {
            _dexQuote = quote;
            _quote = _tpixQuoteView(quote, fromTk, toTk);
            _quotedFor = ctx;
            _quoteError = null;
          });
        }
        throw SwapException(
          _impactBlockedMessage(quote, false, fromTk.symbol),
          _impactBlockedMessage(quote, true, fromTk.symbol),
        );
      }

      // 3) ยอดที่จะจ่ายต้องไม่เกินที่มีจริง (เทียบหน่วยเล็กสุด · รู้ยอดแล้วเท่านั้น)
      _ensureSameWallet(wallet, address);
      final haveWei = _tpixBalanceWei(wallet, fromTk);
      if (haveWei != null && quote.amountInWei > haveWei) {
        final have = TpixDexService.fromWei(haveWei, quote.fromToken.decimals);
        throw SwapException(
          'Insufficient ${fromTk.symbol} — need ${_trimNum(quote.amountIn)}, have ${_trimNum(have)}.',
          '${fromTk.symbol} ไม่พอ — ต้องใช้ ${_trimNum(quote.amountIn)} มี ${_trimNum(have)}',
        );
      }

      // 4) Approve router เท่ายอดของไม้นี้พอดี (เฉพาะโทเคน — TPIX เนทีฟไม่ต้อง)
      if (!quote.fromToken.native) {
        final ok = await _dexService.hasAllowance(
            quote.fromToken, address, quote.amountInWei);
        _ensureSameWallet(wallet, address);
        if (!ok) {
          if (mounted) {
            _snack(th
                ? 'กำลังขอ approve ${fromTk.symbol} — ยืนยันในกระเป๋า'
                : 'Approving ${fromTk.symbol} — confirm in your wallet');
          }
          final approveData = await _dexService.approveCalldata(
              quote.fromToken.address, quote.amountInWei);
          _ensureSameWallet(wallet, address);
          final approveHash = await wallet.sendChainTransaction(
            chainId: chainId,
            to: quote.fromToken.address,
            data: approveData,
            summary: 'Approve ${fromTk.symbol} for TPIX DEX router',
          );
          if (approveHash == null) {
            // เหตุจริงอยู่ใน wallet.error (ปฏิเสธ / สลับเชนไม่ได้ / เครือข่ายล่ม)
            final why = wallet.error;
            throw SwapException(
              SwapNetworkRules.walletErrorText(why, false, _approveRejected),
              SwapNetworkRules.walletErrorText(why, true, _approveRejected),
            );
          }
          final approved = await _dexService.waitConfirmed(approveHash);
          if (!approved) {
            throw const SwapException(
              'Approval not confirmed — try again.',
              'การ approve ยังไม่ยืนยัน ลองใหม่อีกครั้ง',
            );
          }
        }
      }

      // 5) ส่ง swap จริง — service บันทึกไม้กับเซิร์ฟเวอร์ให้แล้ว (fee 0 = อยู่ในพูล)
      final actionText =
          'Swap ${fromTk.symbol} → ${toTk.symbol} — TPIX Trade (TPIX Chain)';
      final result = await _dexService.executeMarketSwap(
        quote: quote,
        walletAddress: address,
        sendTx: ({required String to, Uint8List? data, BigInt? value}) async {
          // ด่านสุดท้ายก่อนเปิดกระเป๋า — ต้องยังเป็นใบเดียวกับตอนกดยืนยัน
          _ensureSameWallet(wallet, address);
          final hash = await wallet.sendChainTransaction(
            chainId: chainId,
            to: to,
            data: data,
            value: value,
            summary: actionText,
          );
          if (hash == null) sendError = wallet.error;
          return hash;
        },
      );

      if (!mounted) return;

      _payController.clear();
      _invalidateQuote();
      final received = '${_trimNum(quote.netOutput)} ${toTk.symbol}';
      _snack(
        result.confirmed
            ? (th
                ? 'สลับสำเร็จ ≈ $received บนเชน TPIX'
                : 'Swapped ≈ $received on TPIX Chain')
            : (th
                ? 'ส่งธุรกรรมแล้ว กำลังรอยืนยันบนเชน'
                : 'Transaction sent — awaiting confirmation'),
        actionLabel: th ? 'ดู tx' : 'View tx',
        onAction: () => launchUrl(
          Uri.parse(result.explorerUrl),
          mode: LaunchMode.externalApplication,
        ),
      );
      // ยอดจริงจากเชน + ราคา TPIX (ไม้นี้ขยับราคาพูลแล้ว)
      _loadBalances();
      wallet.loadPortfolio();
      context.read<MarketProvider>().loadTpixPrice(retries: 0);
    } on SwapException catch (e) {
      if (mounted) {
        // ส่งไม่ออก = เหตุจริงจาก WalletProvider ชัดกว่า "ถูกปฏิเสธหรือส่งไม่สำเร็จ"
        _snack(sendError != null
            ? SwapNetworkRules.walletErrorText(sendError, th, e)
            : e.message(th));
      }
    } catch (_) {
      if (mounted) {
        _snack(SwapNetworkRules.walletErrorText(sendError, th, _swapFailed));
      }
    } finally {
      if (mounted) setState(() => _executing = false);
    }
  }

  /// กระเป๋าถูกสลับ/ตัดการเชื่อมต่อระหว่างที่ไม้ยังวิ่งอยู่ — หยุดก่อนส่งอะไรออกไป
  void _ensureSameWallet(WalletProvider wallet, String address) {
    final now = wallet.address;
    if (now == null || now.toLowerCase() != address.toLowerCase()) {
      throw const SwapException(
        'Wallet changed during the swap — nothing more was sent. Please try again.',
        'กระเป๋าถูกเปลี่ยนระหว่างสลับเหรียญ — ไม่ได้ส่งอะไรเพิ่ม ลองใหม่อีกครั้ง',
      );
    }
  }

  void _snack(String msg, {String? actionLabel, VoidCallback? onAction}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        // มีปุ่ม action (ลิงก์ดู tx) ให้ค้างนานพอที่จะกด
        duration: Duration(seconds: actionLabel != null ? 8 : 2),
        action: actionLabel != null && onAction != null
            ? SnackBarAction(label: actionLabel, onPressed: onAction)
            : null,
      ),
    );
  }

  // ── Price impact (โหมด TPIX) — สี/ข้อความเดียวกับหน้าเทรด ──

  static const Color _impactWarn = Color(0xFFF59E0B);

  Color _impactColor(double impact) {
    if (impact > kTpixMaxPriceImpactPct) return AppColors.tradingRed;
    if (impact >= 3) return _impactWarn;
    if (impact >= 1) return AppColors.textPrimary;
    return AppColors.textSecondary;
  }

  String _impactText(double impact) =>
      impact < 0.01 ? '< 0.01%' : '${impact.toStringAsFixed(2)}%';

  /// ข้อความ "ไม้นี้เกินเพดาน" — จำนวนสูงสุดเป็นหน่วยของเหรียญที่จ่าย (หน้านี้เป็น exact-in)
  String _impactBlockedMessage(TpixDexQuote q, bool th, String fromSym) {
    final impact = q.priceImpact.toStringAsFixed(1);
    final limit = kTpixMaxPriceImpactPct.toStringAsFixed(0);
    final max = q.maxAmountInUnderLimit;
    if (max != null && max > 0) {
      final s = TpixDexService.formatMaxAmount(max);
      return th
          ? 'ไม้นี้ดันราคาพูลไป $impact% เกินเพดาน $limit% — จ่ายได้สูงสุดราว $s $fromSym ต่อไม้'
          : 'This swap moves the pool price by $impact% (limit $limit%). Max ≈ $s $fromSym per swap.';
    }
    return th
        ? 'ไม้นี้ดันราคาพูลไป $impact% เกินเพดาน $limit% — ลองจำนวนน้อยลง'
        : 'This swap moves the pool price by $impact% (limit $limit%). Try a smaller amount.';
  }

  // ── Logos (โหมด TPIX: ห้ามเดาโลโก้จากชื่อของเหรียญบนเชน) ──

  String? _tpixLogoFor(SwapNetwork network, String symbol) =>
      network == SwapNetwork.tpix ? _tpixBySymbol(symbol)?.logo : null;

  bool _chainTokenFor(SwapNetwork network, String symbol) =>
      network == SwapNetwork.tpix &&
      !(_tpixBySymbol(symbol)?.verifiedMajor ?? false);

  // ── Quote field readers (defensive — backend shape is opaque) ──

  double? _receiveAmount() => _readNum(_quote, const [
        'amount_out',
        'amountOut',
        'to_amount',
        'toAmount',
        'output_amount',
        'outputAmount',
        'estimated_output',
      ]);

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    // market watched so USD ≈ lines refresh as live prices stream in
    // (+ คู่บนเชน TPIX ที่ตรวจแล้ว ใช้สร้างรายการเหรียญโหมด TPIX)
    final market = context.watch<MarketProvider>();
    final config = context.watch<ConfigProvider>();

    _syncTpixTokens(config, market);
    final tpixAvailable = SwapNetworkRules.tpixModeAvailable(
        _dexService.lastConfig, _tpixTokens);
    _scheduleTpixFixIfNeeded(tpixAvailable);

    // โหลดยอดใหม่เมื่อ wallet เปลี่ยน (เชื่อมทีหลัง/สลับ address)
    _maybeReloadBalances(wallet);

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(child: _buildHeader(locale)),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 2, 18, 10),
                  child: _NetworkSelector(
                    selected: _network,
                    tpixAvailable: tpixAvailable,
                    checking: !_dexConfigLoaded,
                    locked: _executing,
                    locale: locale,
                    onSelect: (n) => _selectNetwork(n, tpixAvailable),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 0),
                  child: _buildSwapCards(locale, wallet),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 14, 18, 0),
                  child: _buildDetailsCard(locale),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
                  child: _buildCta(locale, wallet, tpixAvailable),
                ),
              ),
              const SliverToBoxAdapter(child: SizedBox(height: 120)),
            ],
          ),
        ),
      ),
    );
  }

  // ── Header ──

  Widget _buildHeader(LocaleProvider locale) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 14, 14, 6),
      child: Row(
        children: [
          ShaderMask(
            shaderCallback: (b) => AppGradients.gold.createShader(b),
            child: Icon(Icons.swap_horiz_rounded,
                size: 26, color: AppColors.white),
          ),
          const SizedBox(width: 10),
          Text(
            locale.isThai ? 'สลับเหรียญ' : 'Swap',
            style: GoogleFonts.inter(
              fontSize: 22,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              letterSpacing: -0.3,
            ),
          ),
          const Spacer(),
          _SettingsChip(
            slippage: _slippage,
            onTap: _openSlippageDialog,
          ),
        ],
      ),
    );
  }

  // ── You pay / You receive cards + flip button ──

  Widget _buildSwapCards(LocaleProvider locale, WalletProvider wallet) {
    final fromSym = _fromSymbol;
    final toSym = _toSymbol;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Column(
          children: [
            _PayCard(
              label: locale.isThai ? 'คุณจ่าย' : 'You pay',
              token: fromSym,
              logoUrl: _tpixLogoFor(_network, fromSym),
              chainToken: _chainTokenFor(_network, fromSym),
              controller: _payController,
              balance: _balanceOf(wallet, fromSym),
              usdValue: _usdEstimate(fromSym, _payAmountRaw()),
              onPickToken: _executing ? null : () => _pickToken(isFrom: true),
              onMax: () => _applyMax(wallet),
              locale: locale,
            ),
            const SizedBox(height: 10),
            _ReceiveCard(
              label: locale.isThai ? 'คุณได้รับ' : 'You receive',
              token: toSym,
              logoUrl: _tpixLogoFor(_network, toSym),
              chainToken: _chainTokenFor(_network, toSym),
              amount: _receiveAmount(),
              quoting: _quoting,
              usdValue: _usdEstimate(toSym, _receiveAmount()),
              onPickToken: _executing ? null : () => _pickToken(isFrom: false),
              locale: locale,
            ),
          ],
        ),
        // Centered gold flip button overlapping the seam between the two cards.
        Positioned.fill(
          child: Align(
            alignment: Alignment.center,
            child: _FlipButton(onTap: _executing ? null : _flipDirection),
          ),
        ),
      ],
    );
  }

  double? _payAmountRaw() => double.tryParse(_payController.text.trim());

  void _setAmountText(String text) {
    _payController.text = text;
    _payController.selection = TextSelection.fromPosition(
      TextPosition(offset: _payController.text.length),
    );
  }

  void _applyMax(WalletProvider wallet) {
    if (_executing) return;
    if (_isTpix) {
      final t = _tpixByKey(_tpixFromKey);
      if (t == null) return;
      final wei = _tpixBalanceWei(wallet, t);
      final dec = _tpixDecimals[t.key];
      // decimals ยังไม่ยืนยันกับเชน = ไม่เติมตัวเลขที่อาจเพี้ยนเป็นล้านเท่า
      if (wei == null || dec == null) return;
      // เชน TPIX ค่าแก๊ส 0 → ใช้ได้เต็มยอดแม้เป็น TPIX เนทีฟ (ไม่ต้องกันไว้จ่ายแก๊ส)
      // เติมจากหน่วยเล็กสุดตรงตัว — "สูงสุด" = ยอดทั้งหมดพอดี ไม่ปัดเกิน
      final max = SwapNetworkRules.maxSpendableWei(
        balanceWei: wei,
        native: t.native,
        gasless: ChainConfig.tpix.isGasless,
      );
      if (max <= BigInt.zero) return;
      _setAmountText(TpixDexService.formatUnits(max, dec));
      return;
    }
    final bal = _balanceOf(wallet, _fromToken);
    if (bal == null || bal <= 0) return;
    _setAmountText(_trimNum(bal));
  }

  /// "ใช้สูงสุด" จากแถบเกินเพดาน price impact — ปัดลงเสมอ (ปัดขึ้นแล้วจะเกินเพดานเอง)
  void _applyImpactMax(TpixDexQuote q) {
    if (_executing) return;
    final max = q.maxAmountInUnderLimit;
    if (max == null || max <= 0) return;
    _setAmountText(TpixDexService.formatMaxAmount(max));
  }

  // USD estimate from live market tickers (real data; null if unknown).
  double? _usdEstimate(String symbol, double? amount) {
    if (amount == null || amount <= 0) return null;
    final market = context.read<MarketProvider>();
    if (_isTpix) {
      final t = _tpixBySymbol(symbol);
      if (t == null) return null;
      if (t.verifiedMajor && t.symbol == 'USDT') return amount;
      if (t.native) {
        final p = market.tpixPrice?.price;
        return p != null && p > 0 ? amount * p : null;
      }
      // เหรียญบนเชน: ใช้ราคาคู่บนเชนกับ USDT ที่ที่อยู่ตรงกันเท่านั้น
      // — ห้ามหยิบราคาตลาดหลักที่ชื่อบังเอิญตรงกัน (เหรียญชื่อ BTC บนเชน TPIX ≠ BTC)
      final pairSymbol = '${t.symbol}-USDT';
      final dex = market.dexPairInfo(pairSymbol);
      if (dex == null || dex.baseAddress.toLowerCase() != t.key) return null;
      for (final tk in market.allTickers) {
        if (tk.isDex && tk.symbol.toUpperCase() == pairSymbol && tk.lastPrice > 0) {
          return amount * tk.lastPrice;
        }
      }
      return null;
    }
    final s = symbol.toUpperCase();
    if (s == 'USDT' || s == 'USDC' || s == 'BUSD' || s == 'DAI') {
      return amount; // stablecoins ≈ 1 USD
    }
    if (s == 'TPIX') {
      final p = market.tpixPrice?.price;
      return p != null ? amount * p : null;
    }
    for (final t in market.allTickers) {
      if (t.baseAsset.toUpperCase() == s && t.lastPrice > 0) {
        return amount * t.lastPrice;
      }
    }
    return null;
  }

  // ── Details card ──

  Widget _buildDetailsCard(LocaleProvider locale) {
    final th = locale.isThai;
    final fromSym = _fromSymbol;
    final toSym = _toSymbol;
    final rows = <Widget>[];

    // Rate — prefer an explicit rate field; otherwise derive from amounts.
    final rate = _readNum(_quote, const ['rate', 'price', 'exchange_rate']) ??
        _derivedRate();
    if (rate != null) {
      rows.add(_DetailRow(
        label: th ? 'อัตราแลกเปลี่ยน' : 'Rate',
        value: '1 $fromSym ≈ ${_trimNum(rate)} $toSym',
      ));
    }

    final dq = _isTpix ? _dexQuote : null;
    if (_isTpix) {
      // ค่าธรรมเนียม 0.3% อยู่ในพูล (รวมในราคาที่ได้แล้ว) — ไม่มีธุรกรรมเก็บแยก
      if (dq != null) {
        rows.add(_DetailRow(
          label: th ? 'ค่าธรรมเนียมพูล (รวมในราคา)' : 'Pool fee (in price)',
          value: '${_trimNum(dq.feeAmount)} $fromSym (${_trimNum(dq.feeRate)}%)',
        ));
      }
      // เชน TPIX ค่าแก๊สเป็น 0
      rows.add(_DetailRow(
        label: th ? 'ค่าธรรมเนียมเครือข่าย' : 'Network fee',
        value: th ? '0 (เชน TPIX ไม่มีค่าแก๊ส)' : '0 (TPIX Chain is gasless)',
      ));
    } else {
      // Platform fee — ตัวเลขจริงจาก router quote (หักจากฝั่งจ่าย)
      final rq = _routerQuote;
      if (rq != null) {
        rows.add(_DetailRow(
          label: th ? 'ค่าธรรมเนียมแพลตฟอร์ม' : 'Platform fee',
          value:
              '${_trimNum(rq.feeAmount)} $fromSym (${_trimNum(rq.feeRate)}%)',
        ));
      }

      // Swap บน BSC — ค่า gas จ่ายเป็น BNB (ไม่ใช่ gasless ของ TPIX)
      rows.add(_DetailRow(
        label: th ? 'ค่าธรรมเนียมเครือข่าย' : 'Network fee',
        value: th ? 'BNB (gas บนเชน BSC)' : 'BNB (BSC network gas)',
      ));
    }

    // Minimum received (after slippage) — only if the quote returns it.
    final minReceived = _readNum(_quote, const [
      'min_received',
      'minReceived',
      'minimum_received',
      'min_amount_out',
      'minAmountOut',
    ]);
    if (minReceived != null) {
      rows.add(_DetailRow(
        label: th ? 'ได้รับขั้นต่ำ' : 'Min. received',
        value: '${_trimNum(minReceived)} $toSym',
      ));
    }

    // Price impact — โหมด TPIX บอกทุกไม้ก่อนกด (พูลยังเล็ก ไม้ไม่กี่ดอลลาร์ก็ขยับราคาได้)
    if (dq != null) {
      rows.add(_DetailRow(
        label: th ? 'ผลต่อราคาพูล' : 'Price impact',
        value: _impactText(dq.priceImpact),
        valueColor: _impactColor(dq.priceImpact),
      ));
    } else {
      final priceImpact =
          _readNum(_quote, const ['price_impact', 'priceImpact', 'impact']);
      if (priceImpact != null) {
        rows.add(_DetailRow(
          label: th ? 'ผลกระทบราคา' : 'Price impact',
          value: '${_trimNum(priceImpact)}%',
        ));
      }
    }

    rows.add(_DetailRow(
      label: th ? 'ค่าคลาดเคลื่อน (Slippage)' : 'Slippage',
      value: '${_trimNum(_slippage)}%',
    ));

    // Route — render only when the quote actually returns a path.
    final routeStr = _routeString();

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.receipt_long_rounded,
                  size: 15, color: AppColors.gold2),
              const SizedBox(width: 8),
              Text(
                th ? 'รายละเอียด' : 'Details',
                style: GoogleFonts.inter(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const Spacer(),
              if (_quoting)
                SizedBox(
                  width: 13,
                  height: 13,
                  child: CircularProgressIndicator(
                      strokeWidth: 1.6, color: AppColors.gold2),
                ),
            ],
          ),
          const SizedBox(height: 10),
          if (_quoteError != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text(
                _quoteError!,
                style: GoogleFonts.inter(
                    fontSize: 11.5, color: AppColors.tradingRed),
              ),
            ),
          ...rows,
          if (dq != null && dq.exceedsImpactLimit) ...[
            const SizedBox(height: 8),
            _ImpactBlockedBanner(
              message: _impactBlockedMessage(dq, th, fromSym),
              canUseMax: (dq.maxAmountInUnderLimit ?? 0) > 0,
              useMaxLabel: th ? 'ใช้สูงสุด' : 'Use max',
              onUseMax: _executing ? null : () => _applyImpactMax(dq),
            ),
          ],
          if (routeStr != null) ...[
            const SizedBox(height: 10),
            Divider(color: AppColors.divider, height: 1),
            const SizedBox(height: 10),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  th ? 'เส้นทาง' : 'Route',
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.textTertiary),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    routeStr,
                    textAlign: TextAlign.right,
                    style: AppTheme.mono(
                        fontSize: 11.5, color: AppColors.textSecondary),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ── CTA ──

  Widget _buildCta(
      LocaleProvider locale, WalletProvider wallet, bool tpixAvailable) {
    final th = locale.isThai;
    final amount = _payAmountRaw();
    final hasAmount = amount != null && amount > 0;
    // quote ต้องเป็นของบริบทปัจจุบันเป๊ะ ๆ (เครือข่าย/เหรียญ/จำนวน/slippage)
    final quoteFresh =
        !_quoting && _quotedFor != null && _quotedFor == _quoteContext();

    final bool pairOk;
    final bool quoteOk;
    if (_isTpix) {
      pairOk = tpixAvailable && _tpixSelectionOk();
      final dq = _dexQuote;
      // ไม้ที่ดันราคาพูลเกินเพดาน = กดไม่ได้ (กติกาเดียวกับเว็บ/หน้าเทรด)
      quoteOk = dq != null && !dq.exceedsImpactLimit;
    } else {
      pairOk = _fromToken != _toToken;
      quoteOk = _routerQuote != null;
    }

    final ready = wallet.isConnected &&
        hasAmount &&
        pairOk &&
        quoteOk &&
        quoteFresh &&
        !_executing;

    final label = !wallet.isConnected
        ? (th ? 'เชื่อมกระเป๋าก่อน' : 'Connect wallet first')
        : _executing
            ? (th ? 'กำลังสลับ...' : 'Swapping...')
            : (th ? 'สลับตอนนี้' : 'Swap Now');

    return Column(
      children: [
        GradientButton(
          text: label,
          variant: ButtonVariant.gold,
          icon: Icons.swap_horiz_rounded,
          isLoading: _executing || (_quoting && hasAmount),
          onPressed: ready ? _reviewSwap : null,
        ),
        const SizedBox(height: 10),
        Text(
          _isTpix
              ? (th
                  ? 'ราคาจริงจากพูล TPIX DEX (เชน TPIX) • settle บนเชนจริง เหรียญเข้ากระเป๋าคุณ'
                  : 'Live pool price on TPIX DEX (TPIX Chain) • settles on-chain to your wallet')
              : (th
                  ? 'ราคาจริงจาก PancakeSwap (BSC) • settle บนเชนจริง เหรียญเข้ากระเป๋าคุณ'
                  : 'Live price from PancakeSwap (BSC) • settles on-chain to your wallet'),
          style: GoogleFonts.inter(
            fontSize: 10.5,
            color: AppColors.textTertiary,
          ),
          textAlign: TextAlign.center,
        ),
        // Linked wallet — แอปจะสลับไป TPIX Wallet ให้ยืนยันทีละธุรกรรม (approve → swap)
        if (wallet.isConnected && wallet.isLinkedWallet) ...[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(
              color: AppColors.goldTint,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.goldBorder, width: 0.8),
            ),
            child: Row(
              children: [
                Icon(Icons.swap_calls_rounded,
                    size: 14, color: AppColors.gold2),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    th
                        ? 'จะเปิด TPIX Wallet ให้ยืนยันธุรกรรมทีละรายการ แล้วกลับมาที่นี่อัตโนมัติ'
                        : 'TPIX Wallet will open to confirm each transaction, then return here automatically.',
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  // ── Quote helpers ──

  double? _derivedRate() {
    final pay = _payAmountRaw();
    final out = _receiveAmount();
    if (pay == null || pay <= 0 || out == null) return null;
    return out / pay;
  }

  String? _routeString() {
    final raw = _quote?['route'] ?? _quote?['path'] ?? _quote?['hops'];
    if (raw is List && raw.isNotEmpty) {
      final parts = raw.map((e) {
        if (e is String) return e;
        if (e is Map) {
          return (e['symbol'] ?? e['token'] ?? e['name'] ?? '').toString();
        }
        return e.toString();
      }).where((s) => s.isNotEmpty);
      if (parts.isNotEmpty) return parts.join(' → ');
    }
    if (raw is String && raw.isNotEmpty) return raw;
    return null;
  }

  // ── Generic readers / formatters ──

  static double? _readNum(Map<String, dynamic>? src, List<String> keys) {
    if (src == null) return null;
    for (final k in keys) {
      final v = src[k];
      if (v is num) return v.toDouble();
      if (v is String) {
        final p = double.tryParse(v);
        if (p != null) return p;
      }
    }
    return null;
  }

  static String _trimNum(double v) {
    if (v == 0) return '0';
    final abs = v.abs();
    final decimals = abs >= 1000
        ? 2
        : abs >= 1
            ? 4
            : 8;
    var s = v.toStringAsFixed(decimals);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '');
      s = s.replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }
}

// ── Network selector (BSC / TPIX Chain) ──

class _NetworkSelector extends StatelessWidget {
  final SwapNetwork selected;
  final bool tpixAvailable;
  final bool checking;
  final bool locked;
  final LocaleProvider locale;
  final ValueChanged<SwapNetwork> onSelect;

  const _NetworkSelector({
    required this.selected,
    required this.tpixAvailable,
    required this.checking,
    required this.locked,
    required this.locale,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final th = locale.isThai;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: AppColors.bgInputStrong,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.bgCardBorder),
          ),
          child: Row(
            children: [
              Expanded(
                child: _NetworkOption(
                  title: 'BSC',
                  subtitle: 'PancakeSwap',
                  active: selected == SwapNetwork.bsc,
                  enabled: !locked,
                  onTap: () => onSelect(SwapNetwork.bsc),
                ),
              ),
              const SizedBox(width: 4),
              Expanded(
                child: _NetworkOption(
                  title: 'TPIX Chain',
                  subtitle: 'TPIX DEX',
                  active: selected == SwapNetwork.tpix,
                  // ไม่พร้อม = ยังกดได้เพื่อบอกเหตุผล แต่ดูเทาและไม่สลับจริง
                  enabled: !locked,
                  dimmed: !tpixAvailable,
                  badge: tpixAvailable
                      ? null
                      : (checking ? '…' : (th ? 'เร็ว ๆ นี้' : 'Soon')),
                  onTap: () => onSelect(SwapNetwork.tpix),
                ),
              ),
            ],
          ),
        ),
        if (!tpixAvailable)
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 6, 4, 0),
            child: Text(
              checking
                  ? (th ? 'กำลังตรวจสอบ TPIX DEX…' : 'Checking TPIX DEX…')
                  : (th
                      ? 'สลับบนเชน TPIX: เร็ว ๆ นี้'
                      : 'TPIX Chain swaps: coming soon'),
              style: GoogleFonts.inter(
                fontSize: 10.5,
                color: AppColors.textTertiary,
              ),
            ),
          ),
      ],
    );
  }
}

class _NetworkOption extends StatelessWidget {
  final String title;
  final String subtitle;
  final bool active;
  final bool enabled;
  final bool dimmed;
  final String? badge;
  final VoidCallback onTap;

  const _NetworkOption({
    required this.title,
    required this.subtitle,
    required this.active,
    required this.enabled,
    required this.onTap,
    this.dimmed = false,
    this.badge,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: enabled ? onTap : null,
      behavior: HitTestBehavior.opaque,
      child: Opacity(
        opacity: dimmed ? 0.5 : 1,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          decoration: BoxDecoration(
            color: active ? AppColors.bgTertiary : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: active ? AppColors.goldBorder : Colors.transparent,
              width: 1,
            ),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Flexible(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.inter(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color:
                            active ? AppColors.gold1 : AppColors.textSecondary,
                      ),
                    ),
                    Text(
                      subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.inter(
                        fontSize: 10,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              if (badge != null) ...[
                const SizedBox(width: 6),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF59E0B).withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    badge!,
                    style: GoogleFonts.inter(
                      fontSize: 8.5,
                      fontWeight: FontWeight.w700,
                      color: const Color(0xFFF59E0B),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

// ── Impact-blocked banner (โหมด TPIX) ──

class _ImpactBlockedBanner extends StatelessWidget {
  final String message;
  final bool canUseMax;
  final String useMaxLabel;
  final VoidCallback? onUseMax;

  const _ImpactBlockedBanner({
    required this.message,
    required this.canUseMax,
    required this.useMaxLabel,
    required this.onUseMax,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(10, 8, 6, 8),
      decoration: BoxDecoration(
        color: AppColors.tradingRedBg,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.tradingRed, width: 0.8),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              message,
              style: GoogleFonts.inter(
                fontSize: 10.5,
                height: 1.4,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          if (canUseMax)
            TextButton(
              onPressed: onUseMax,
              style: TextButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                minimumSize: const Size(0, 30),
                foregroundColor: AppColors.gold1,
              ),
              child: Text(
                useMaxLabel,
                style: GoogleFonts.inter(
                    fontSize: 11, fontWeight: FontWeight.w700),
              ),
            ),
        ],
      ),
    );
  }
}

// ── Settings chip (slippage entry point) ──

class _SettingsChip extends StatelessWidget {
  final double slippage;
  final VoidCallback onTap;

  const _SettingsChip({required this.slippage, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          color: accent.goldTint,
          border: Border.all(color: accent.goldBorder, width: 1.2),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.tune_rounded, size: 14, color: accent.g2),
            const SizedBox(width: 6),
            Text(
              '${_fmt(slippage)}%',
              style: AppTheme.mono(
                fontSize: 12,
                color: accent.g1,
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _fmt(double v) {
    var s = v.toStringAsFixed(2);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }
}

// ── You pay card ──

class _PayCard extends StatelessWidget {
  final String label;
  final String token;
  final String? logoUrl;
  final bool chainToken;
  final TextEditingController controller;
  final double? balance;
  final double? usdValue;
  final VoidCallback? onPickToken; // null = ล็อกไว้ระหว่างส่งธุรกรรม
  final VoidCallback onMax;
  final LocaleProvider locale;

  const _PayCard({
    required this.label,
    required this.token,
    required this.controller,
    required this.balance,
    required this.usdValue,
    required this.onPickToken,
    required this.onMax,
    required this.locale,
    this.logoUrl,
    this.chainToken = false,
  });

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: 18,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                label,
                style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textTertiary,
                  letterSpacing: 1.0,
                ),
              ),
              const Spacer(),
              if (balance != null)
                GestureDetector(
                  onTap: onMax,
                  behavior: HitTestBehavior.opaque,
                  child: Text(
                    '${locale.isThai ? 'ยอด' : 'Bal'}: ${_fmtBal(balance!)}  ·  MAX',
                    style: AppTheme.mono(
                      fontSize: 10.5,
                      color: AppColors.gold2,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: controller,
                  keyboardType: const TextInputType.numberWithOptions(
                      decimal: true),
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                  ],
                  style: GoogleFonts.jetBrainsMono(
                    fontSize: 28,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                  decoration: InputDecoration(
                    border: InputBorder.none,
                    isDense: true,
                    contentPadding: EdgeInsets.zero,
                    hintText: '0.0',
                    hintStyle: GoogleFonts.jetBrainsMono(
                      fontSize: 28,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textDisabled,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              _TokenPill(
                symbol: token,
                logoUrl: logoUrl,
                chainToken: chainToken,
                onTap: onPickToken,
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            usdValue != null ? '≈ \$${_fmtUsd(usdValue!)}' : '≈ \$0.00',
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  String _fmtBal(double v) {
    if (v >= 1000) return v.toStringAsFixed(2);
    if (v >= 1) return v.toStringAsFixed(4);
    return v.toStringAsFixed(6);
  }

  String _fmtUsd(double v) {
    return v.toStringAsFixed(2);
  }
}

// ── You receive card ──

class _ReceiveCard extends StatelessWidget {
  final String label;
  final String token;
  final String? logoUrl;
  final bool chainToken;
  final double? amount;
  final bool quoting;
  final double? usdValue;
  final VoidCallback? onPickToken; // null = ล็อกไว้ระหว่างส่งธุรกรรม
  final LocaleProvider locale;

  const _ReceiveCard({
    required this.label,
    required this.token,
    required this.amount,
    required this.quoting,
    required this.usdValue,
    required this.onPickToken,
    required this.locale,
    this.logoUrl,
    this.chainToken = false,
  });

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 18,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 1.0,
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(child: _amountWidget()),
              const SizedBox(width: 10),
              _TokenPill(
                symbol: token,
                logoUrl: logoUrl,
                chainToken: chainToken,
                onTap: onPickToken,
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            usdValue != null ? '≈ \$${usdValue!.toStringAsFixed(2)}' : '≈ \$0.00',
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _amountWidget() {
    if (quoting && amount == null) {
      return Text(
        '…',
        style: GoogleFonts.jetBrainsMono(
          fontSize: 28,
          fontWeight: FontWeight.w600,
          color: AppColors.textDisabled,
        ),
      );
    }
    final text = amount != null ? _fmt(amount!) : '0.0';
    // Gold-gradient numerals for the received amount (read-only).
    return ShaderMask(
      shaderCallback: (b) => AppGradients.gold.createShader(b),
      child: Text(
        text,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: GoogleFonts.jetBrainsMono(
          fontSize: 28,
          fontWeight: FontWeight.w700,
          color: AppColors.white, // masked by gold gradient
        ),
      ),
    );
  }

  String _fmt(double v) {
    if (v == 0) return '0';
    final abs = v.abs();
    final decimals = abs >= 1000
        ? 2
        : abs >= 1
            ? 4
            : 8;
    var s = v.toStringAsFixed(decimals);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }
}

// ── Token pill (CoinChip + symbol + chevron) ──

class _TokenPill extends StatelessWidget {
  final String symbol;
  final VoidCallback? onTap;
  final String? logoUrl;

  /// เหรียญบนเชน TPIX ที่ไม่ใช่สกุลหลัก — ห้ามเดาโลโก้จากชื่อ (ดู CoinLogo)
  final bool chainToken;

  const _TokenPill({
    required this.symbol,
    required this.onTap,
    this.logoUrl,
    this.chainToken = false,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.fromLTRB(6, 5, 10, 5),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          color: AppColors.bgInputStrong,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            CoinChip(
              symbol: symbol,
              size: 28,
              logoUrl: logoUrl,
              chainToken: chainToken,
            ),
            const SizedBox(width: 8),
            Text(
              symbol,
              style: GoogleFonts.inter(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(width: 2),
            Icon(Icons.keyboard_arrow_down_rounded,
                size: 18, color: AppColors.textSecondary),
          ],
        ),
      ),
    );
  }
}

// ── Centered gold flip button ──

class _FlipButton extends StatelessWidget {
  final VoidCallback? onTap; // null = ล็อกไว้ระหว่างส่งธุรกรรม
  const _FlipButton({required this.onTap});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: 44,
        height: 44,
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: accent.goldGradient,
          border: Border.all(color: AppColors.bgPrimary, width: 3),
          boxShadow: [
            BoxShadow(
              color: accent.goldGlow.withValues(alpha: 0.45),
              blurRadius: 16,
              spreadRadius: -2,
            ),
          ],
        ),
        child: Center(
          child: Icon(Icons.swap_vert_rounded,
              color: AppColors.goldTextOn, size: 22),
        ),
      ),
    );
  }
}

// ── Detail row ──

class _DetailRow extends StatelessWidget {
  final String label;
  final String value;
  final Color? valueColor;

  const _DetailRow({
    required this.label,
    required this.value,
    this.valueColor,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
                fontSize: 12, color: AppColors.textTertiary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: AppTheme.mono(
                fontSize: 12,
                color: valueColor ?? AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Token picker bottom sheet ──

class _TokenPickerSheet extends StatefulWidget {
  final List<String> tokens;
  final double? Function(String) balanceOf;
  final String selected;
  final String disabled; // the token chosen on the other side
  final LocaleProvider locale;

  /// โหมด TPIX: โลโก้ที่รับรองแล้ว / เหรียญบนเชนที่ห้ามเดาโลโก้จากชื่อ (null = โหมด BSC)
  final String? Function(String)? logoOf;
  final bool Function(String)? chainTokenOf;

  const _TokenPickerSheet({
    required this.tokens,
    required this.balanceOf,
    required this.selected,
    required this.disabled,
    required this.locale,
    this.logoOf,
    this.chainTokenOf,
  });

  @override
  State<_TokenPickerSheet> createState() => _TokenPickerSheetState();
}

class _TokenPickerSheetState extends State<_TokenPickerSheet> {
  final _searchController = TextEditingController();
  String _query = '';

  @override
  void initState() {
    super.initState();
    _searchController.addListener(
        () => setState(() => _query = _searchController.text.toUpperCase()));
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = widget.locale;
    final filtered = _query.isEmpty
        ? widget.tokens
        : widget.tokens.where((t) => t.contains(_query)).toList();

    return Container(
      margin: EdgeInsets.only(
        top: MediaQuery.of(context).padding.top + 60,
      ),
      decoration: BoxDecoration(
        color: AppColors.bgElevated,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(
          top: BorderSide(color: AppColors.goldBorder, width: 1.4),
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 12),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.bgCardBorder,
              borderRadius: BorderRadius.circular(999),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 14, 18, 8),
            child: Row(
              children: [
                Text(
                  locale.isThai ? 'เลือกเหรียญ' : 'Select token',
                  style: GoogleFonts.inter(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const Spacer(),
                IconButton(
                  icon: Icon(Icons.close_rounded,
                      color: AppColors.textTertiary, size: 20),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18),
            child: Container(
              decoration: BoxDecoration(
                color: AppColors.bgInput,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.bgCardBorder),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(
                children: [
                  Icon(Icons.search_rounded,
                      size: 18, color: AppColors.textTertiary),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TextField(
                      controller: _searchController,
                      style: GoogleFonts.inter(
                          fontSize: 14, color: AppColors.textPrimary),
                      decoration: InputDecoration(
                        border: InputBorder.none,
                        isDense: true,
                        contentPadding:
                            const EdgeInsets.symmetric(vertical: 12),
                        hintText:
                            locale.isThai ? 'ค้นหาเหรียญ' : 'Search token',
                        hintStyle: GoogleFonts.inter(
                            fontSize: 14, color: AppColors.textDisabled),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 6),
          Flexible(
            child: filtered.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(28),
                    child: Text(
                      locale.isThai ? 'ไม่พบเหรียญ' : 'No tokens found',
                      style: GoogleFonts.inter(
                          fontSize: 13, color: AppColors.textTertiary),
                    ),
                  )
                : ListView.builder(
                    shrinkWrap: true,
                    padding: const EdgeInsets.fromLTRB(12, 4, 12, 24),
                    itemCount: filtered.length,
                    itemBuilder: (_, i) {
                      final sym = filtered[i];
                      final bal = widget.balanceOf(sym);
                      final isSelected = sym == widget.selected;
                      final isDisabled = sym == widget.disabled;
                      return Opacity(
                        opacity: isDisabled ? 0.4 : 1,
                        child: ListTile(
                          enabled: !isDisabled,
                          onTap: isDisabled
                              ? null
                              : () => Navigator.pop(context, sym),
                          leading: CoinChip(
                            symbol: sym,
                            size: 38,
                            logoUrl: widget.logoOf?.call(sym),
                            chainToken: widget.chainTokenOf?.call(sym) ?? false,
                          ),
                          title: Text(
                            sym,
                            style: GoogleFonts.inter(
                              fontSize: 15,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary,
                            ),
                          ),
                          subtitle: bal != null
                              ? Text(
                                  '${locale.isThai ? 'ยอดคงเหลือ' : 'Balance'}: ${_fmtBal(bal)}',
                                  style: AppTheme.mono(
                                    fontSize: 11,
                                    color: AppColors.textTertiary,
                                  ),
                                )
                              : null,
                          trailing: isSelected
                              ? Icon(Icons.check_circle_rounded,
                                  color: AppColors.gold2, size: 20)
                              : null,
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }

  String _fmtBal(double v) {
    if (v >= 1000) return v.toStringAsFixed(2);
    if (v >= 1) return v.toStringAsFixed(4);
    return v.toStringAsFixed(6);
  }
}

// ── Slippage dialog ──

class _SlippageDialog extends StatefulWidget {
  final double current;
  final LocaleProvider locale;

  const _SlippageDialog({required this.current, required this.locale});

  @override
  State<_SlippageDialog> createState() => _SlippageDialogState();
}

class _SlippageDialogState extends State<_SlippageDialog> {
  late double _value;
  final _customController = TextEditingController();

  static const _presets = [0.1, 0.5, 1.0];

  @override
  void initState() {
    super.initState();
    _value = widget.current;
    if (!_presets.contains(_value)) {
      _customController.text = _fmt(_value);
    }
  }

  @override
  void dispose() {
    _customController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = widget.locale;
    return AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: AppColors.goldBorder),
      ),
      title: Text(
        locale.isThai ? 'ตั้งค่าความคลาดเคลื่อน' : 'Slippage tolerance',
        style: GoogleFonts.inter(
            fontSize: 16,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            children: _presets.map((p) {
              final active = (_value - p).abs() < 0.0001;
              return GestureDetector(
                onTap: () => setState(() {
                  _value = p;
                  _customController.clear();
                }),
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    color:
                        active ? AppColors.goldTint : AppColors.bgTertiary,
                    border: Border.all(
                      color: active
                          ? AppColors.goldBorder
                          : AppColors.bgCardBorder,
                      width: active ? 1.4 : 1,
                    ),
                  ),
                  child: Text(
                    '${_fmt(p)}%',
                    style: AppTheme.mono(
                      fontSize: 13,
                      color:
                          active ? AppColors.gold1 : AppColors.textSecondary,
                    ),
                  ),
                ),
              );
            }).toList(),
          ),
          const SizedBox(height: 14),
          Container(
            decoration: BoxDecoration(
              color: AppColors.bgInput,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.bgCardBorder),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              children: [
                Text(
                  locale.isThai ? 'กำหนดเอง' : 'Custom',
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.textTertiary),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: _customController,
                    keyboardType: const TextInputType.numberWithOptions(
                        decimal: true),
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                    ],
                    textAlign: TextAlign.right,
                    style: AppTheme.mono(fontSize: 14),
                    onChanged: (v) {
                      final parsed = double.tryParse(v);
                      if (parsed != null) setState(() => _value = parsed);
                    },
                    decoration: const InputDecoration(
                      border: InputBorder.none,
                      isDense: true,
                      contentPadding: EdgeInsets.symmetric(vertical: 10),
                      hintText: '0.5',
                    ),
                  ),
                ),
                const SizedBox(width: 4),
                Text('%',
                    style: GoogleFonts.inter(
                        fontSize: 13, color: AppColors.textSecondary)),
              ],
            ),
          ),
          if (_value > 5) ...[
            const SizedBox(height: 10),
            Text(
              locale.isThai
                  ? 'ค่าสูงอาจทำให้ถูกเทรดในราคาที่เสียเปรียบ'
                  : 'High slippage may result in an unfavorable rate',
              style: GoogleFonts.inter(
                  fontSize: 11, color: AppColors.tradingRed),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(locale.t('common.cancel'),
              style: TextStyle(color: AppColors.textTertiary)),
        ),
        ElevatedButton(
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.gold2,
            foregroundColor: AppColors.goldTextOn,
            shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10)),
          ),
          onPressed: (_value > 0 && _value <= 50)
              ? () => Navigator.pop(context, _value)
              : null,
          child: Text(locale.t('common.save'),
              style: TextStyle(color: AppColors.goldTextOn)),
        ),
      ],
    );
  }

  String _fmt(double v) {
    var s = v.toStringAsFixed(2);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }
}

// ── Review sheet — ยืนยันก่อนส่ง swap จริงบนเชน (BSC หรือ TPIX Chain) ──

class _ReviewSheet extends StatelessWidget {
  final String fromToken;
  final String toToken;
  final double payAmount;
  final double? receiveAmount;
  final String feeText;
  final String minReceivedText;

  /// เครือข่ายที่ไม้นี้จะลงจริง — โชว์ชัดก่อนกดยืนยัน
  final String networkLabel;

  /// price impact (เฉพาะโหมด TPIX) — null = ไม่แสดงแถวนี้
  final String? priceImpactText;
  final Color? priceImpactColor;
  final String? fromLogo;
  final String? toLogo;
  final bool fromChainToken;
  final bool toChainToken;
  final LocaleProvider locale;
  final VoidCallback onConfirm;

  const _ReviewSheet({
    required this.fromToken,
    required this.toToken,
    required this.payAmount,
    required this.receiveAmount,
    required this.feeText,
    required this.minReceivedText,
    required this.networkLabel,
    required this.locale,
    required this.onConfirm,
    this.priceImpactText,
    this.priceImpactColor,
    this.fromLogo,
    this.toLogo,
    this.fromChainToken = false,
    this.toChainToken = false,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.bgElevated,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          border: Border(
            top: BorderSide(color: AppColors.goldBorder, width: 1.4),
          ),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: AppColors.bgCardBorder,
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  locale.isThai ? 'ตรวจสอบการสลับ' : 'Review swap',
                  style: GoogleFonts.inter(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 16),
                _line(
                  CoinChip(
                    symbol: fromToken,
                    size: 34,
                    logoUrl: fromLogo,
                    chainToken: fromChainToken,
                  ),
                  locale.isThai ? 'จ่าย' : 'Pay',
                  '${_fmt(payAmount)} $fromToken',
                ),
                const SizedBox(height: 6),
                Center(
                  child: Icon(Icons.arrow_downward_rounded,
                      size: 18, color: AppColors.gold2),
                ),
                const SizedBox(height: 6),
                _line(
                  CoinChip(
                    symbol: toToken,
                    size: 34,
                    logoUrl: toLogo,
                    chainToken: toChainToken,
                  ),
                  locale.isThai ? 'รับ' : 'Receive',
                  receiveAmount != null
                      ? '${_fmt(receiveAmount!)} $toToken'
                      : '—',
                  valueColor: AppColors.gold1,
                ),
                const SizedBox(height: 16),
                // สรุป fee + ขั้นต่ำที่ได้รับ ก่อนกดยืนยันส่งขึ้นเชนจริง
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.goldTint,
                    borderRadius: BorderRadius.circular(12),
                    border:
                        Border.all(color: AppColors.goldBorder, width: 1),
                  ),
                  child: Column(
                    children: [
                      _infoRow(
                          locale.isThai ? 'ค่าธรรมเนียม' : 'Fee', feeText),
                      const SizedBox(height: 6),
                      _infoRow(
                          locale.isThai ? 'ได้รับขั้นต่ำ' : 'Min received',
                          minReceivedText),
                      if (priceImpactText != null) ...[
                        const SizedBox(height: 6),
                        _infoRow(
                          locale.isThai ? 'ผลต่อราคาพูล' : 'Price impact',
                          priceImpactText!,
                          valueColor: priceImpactColor,
                        ),
                      ],
                      const SizedBox(height: 6),
                      _infoRow(
                        locale.isThai ? 'เครือข่าย' : 'Network',
                        networkLabel,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                GradientButton(
                  text: locale.isThai
                      ? 'ยืนยันสลับ (ส่งขึ้นเชนจริง)'
                      : 'Confirm swap (on-chain)',
                  variant: ButtonVariant.gold,
                  onPressed: onConfirm,
                ),
                const SizedBox(height: 8),
                Center(
                  child: GestureDetector(
                    onTap: () => Navigator.pop(context),
                    behavior: HitTestBehavior.opaque,
                    child: Padding(
                      padding: const EdgeInsets.all(6),
                      child: Text(
                        locale.isThai ? 'ยกเลิก' : 'Cancel',
                        style: GoogleFonts.inter(
                          fontSize: 13,
                          color: AppColors.textTertiary,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _line(Widget chip, String label, String value, {Color? valueColor}) {
    return Row(
      children: [
        chip,
        const SizedBox(width: 12),
        Text(
          label,
          style: GoogleFonts.inter(
              fontSize: 13, color: AppColors.textTertiary),
        ),
        const Spacer(),
        Text(
          value,
          style: AppTheme.mono(
            fontSize: 15,
            color: valueColor ?? AppColors.textPrimary,
          ),
        ),
      ],
    );
  }

  Widget _infoRow(String label, String value, {Color? valueColor}) {
    return Row(
      children: [
        Text(
          label,
          style:
              GoogleFonts.inter(fontSize: 11.5, color: AppColors.textTertiary),
        ),
        const Spacer(),
        Text(
          value,
          style: AppTheme.mono(
              fontSize: 11.5, color: valueColor ?? AppColors.textSecondary),
        ),
      ],
    );
  }

  String _fmt(double v) {
    if (v == 0) return '0';
    final abs = v.abs();
    final decimals = abs >= 1000
        ? 2
        : abs >= 1
            ? 4
            : 8;
    var s = v.toStringAsFixed(decimals);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }
}
