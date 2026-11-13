/// TPIX TRADE — Trade Screen (Luxury Dark / Gilded Metal)
/// Pair header · gilded chart card with gold timeframe pills · depth-bar order
/// book · Buy/Sell order form. Sits on the gunmetal+gold backdrop with ambient
/// fireflies. All trading data is real (market + config + wallet providers).
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'dart:ui';
import 'package:flutter/material.dart';
import '../../core/theme/app_fonts.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/config_provider.dart';
import '../../providers/ai_bot_provider.dart';
import '../../services/api_service.dart';
import '../../services/bsc_swap_service.dart';
import '../../services/tpix_dex_service.dart';
import '../../services/trade_preflight.dart';
import '../../services/trading_fee_service.dart';
import '../../models/bsc_trade_tokens.dart';
import '../../utils/crypto_logos.dart';
import '../../utils/kyc_nav.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/buy_tpix_button.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/coin_logo.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/price_text.dart';
import '../../widgets/trading/trading_chart.dart';
import '../../widgets/trading/chart_type_toggle.dart';
import '../../widgets/obsidian/obsidian_surface.dart';
import '../../models/api_models.dart';

class TradeScreen extends StatefulWidget {
  const TradeScreen({super.key});

  @override
  State<TradeScreen> createState() => _TradeScreenState();
}

class _TradeScreenState extends State<TradeScreen>
    with SingleTickerProviderStateMixin {
  late TabController _orderTypeTab;
  bool _isBuy = true;
  bool _isSubmitting = false;
  String _timeframe = '1h';
  String _chartType = 'candle';

  /// โหมดบอท — เจ้าของสั่ง "การเข้าไม้ ออกไม้ ต้องแสดงชัดเจนในเส้นกราฟเมื่อเปิดโหมดบอท"
  /// เปิด = ป้ายของบอทพร้อมข้อความ + เส้นต้นทุน/SL/TP ของไม้ที่บอทถืออยู่ในคู่นี้
  /// ไม่แตะฟอร์มซื้อ/ขาย — ผู้ใช้ยังเทรดเองได้ตามปกติ บอทใช้กระเป๋า/พอร์ตของมันเอง
  bool _botMode = true;
  String _lastMarkersJson = '';
  String _lastLinesJson = '';
  String? _lastTradesPair;
  final _amountController = TextEditingController();
  final _chartKey = GlobalKey<TradingChartState>();

  // ── เทรดจริงบน BSC (สอดคล้องกับเว็บ) ──
  final _swapService = BscSwapService();
  // ยอดคงเหลือของ base/quote อ่านตรงจาก BSC RPC (null = ยังไม่โหลด)
  double? _bscBaseBalance;
  double? _bscQuoteBalance;
  String? _bscBalanceKey; // pair|address ล่าสุดที่โหลดไว้ — กันโหลดซ้ำ
  // ── เทรดจริงบนเชน TPIX (AMM ของเราเอง) ──
  final _dexService = TpixDexService();
  // ยอดคงเหลือของ base/quote อ่านตรงจาก RPC ของเชน TPIX (null = ยังไม่โหลด)
  double? _dexBaseBalance;
  double? _dexQuoteBalance;
  // ยอดเดียวกันแบบหน่วยเล็กสุด (ตรงตัว) + decimals ที่ยืนยันกับเชนแล้ว
  // ใช้กับปุ่ม % และด่านยอดไม่พอ — double ปัดเกินยอดจริงได้เมื่อถือหลักร้อยล้านเหรียญ
  BigInt? _dexBaseBalanceWei;
  BigInt? _dexQuoteBalanceWei;
  int? _dexBaseDecimals;
  String? _dexBalanceKey;
  // ยอดสองตัวบนเป็นของคู่/กระเป๋าไหน — อ่านพลาดรอบถัดไปจะคงค่านี้ไว้ (ห้ามโชว์ 0 แทน)
  // แต่ห้ามเอายอดของคู่/กระเป๋าอื่นมาโชว์
  String? _dexBalancesFor;
  TpixDexQuote? _dexQuote;
  // ข้อความเมื่อขอ quote ไม่ได้ (ไม้ใหญ่เกินพูล / เชนติดต่อไม่ได้) — แสดงใต้ฟอร์มก่อนกดส่ง
  String? _dexQuoteError;
  // ลำดับคำขอเติมจำนวนจากปุ่ม % (คำนวณผ่าน router แบบ async) — กดรัวเอาอันล่าสุด
  int _quickFillSeq = 0;
  // คู่ของ quote ที่แสดงอยู่ — เปลี่ยนคู่แล้วต้องขอใหม่
  String? _previewPair;
  // ที่อยู่สัญญาโหลดจากทะเบียนบนเซิร์ฟเวอร์แล้วหรือยัง — ก่อนโหลดเสร็จอย่าเพิ่งตัดสินว่า
  // "ยังไม่เปิด" ไม่งั้นผู้ใช้จะเห็น Coming Soon แว้บหนึ่งทั้งที่กระดานเปิดอยู่
  bool _dexConfigLoaded = false;

  // Preview ราคาจริงจาก PancakeSwap router (debounce)
  BscSwapQuote? _marketQuote;
  bool _quotingPreview = false;
  Timer? _previewDebounce;
  int _previewSeq = 0;
  // ข้อความเมื่อขอ quote ฝั่ง BSC ไม่ได้ (คู่ถูกปิด / ตรวจค่าธรรมเนียมไม่ได้ / ไม่มีสภาพคล่อง)
  // — แสดงใต้ฟอร์มก่อนกดส่ง ไม่ใช่ให้ไปรู้ตอนกด
  String? _bscQuoteError;
  // quote ที่แสดงอยู่เป็นของไม้ไหน (คู่|ฝั่ง|จำนวน|slippage|กระเป๋า) — ใช้ผูก "ได้รับอย่างน้อย"
  // ที่ผู้ใช้เห็น กับไม้ที่กดส่ง: ราคาใหม่แย่กว่าที่เห็น = หยุด ให้ยืนยันอีกครั้ง
  String? _previewKey;
  // ปุ่ม % ฝั่งซื้อบน BSC: ยอด quote ที่จะจ่าย (ตัดทศนิยมทิ้งจากยอดจริง) ผูกกับข้อความจำนวนที่เติม
  // — ส่งยอดนี้ตรง ๆ แทน "จำนวน × ราคาตลาดตอนกด" ที่ขยับได้จนเกินยอดในกระเป๋า
  double? _quickSpend;
  String? _quickSpendKey;
  // คำสั่งที่กำลังยกเลิกอยู่ (กันกดซ้ำ)
  final Set<String> _cancelling = <String>{};

  // Gold timeframe pills surfaced on the chart card.
  static const _pillTimeframes = ['15m', '1h', '4h', '1d', '1w'];
  static const _pillLabels = ['15m', '1H', '4H', '1D', '1W'];

  // index: 0=limit (วางที่หน้าเว็บ — แท็บนี้อธิบายเท่านั้น), 1=market
  bool get _isMarket => _orderTypeTab.index == 1;

  @override
  void initState() {
    super.initState();
    // เริ่มที่ Market — แอปส่งได้เฉพาะ market order
    // (คำสั่งตั้งราคาวางที่หน้าเว็บ แท็บ Limit อธิบายเรื่องนี้ · ไม่มีแท็บ Stop-limit ปลอมแล้ว)
    _orderTypeTab = TabController(length: 2, vsync: this, initialIndex: 1);
    // Tab change ใช้ handler พิเศษ (rebuild + ขอ quote ใหม่)
    _orderTypeTab.addListener(_handleOrderTypeChange);
    _amountController.addListener(_rebuildOnInputChange);
    final market = context.read<MarketProvider>();
    market.loadOrderBook();
    market.loadKlines();
    // Refresh config ถ้า stale (เข้าหน้า trade อาจห่างจาก splash นาน)
    // + sync ConfigProvider กับ chain ปัจจุบันของ wallet (กัน fee mismatch
    //   หลัง backend preferences sync เปลี่ยน default_chain_id)
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final config = context.read<ConfigProvider>();
      final wallet = context.read<WalletProvider>();
      config.refreshIfStale();
      if (wallet.isConnected) {
        config.setActiveChain(wallet.activeChainId);
      }
      // ราคา TPIX อาจยังเป็น 0 ถ้าตอนเปิดแอปโหลดไม่ทัน — ขอใหม่ทุกครั้งที่เข้าหน้าเทรด
      // (ยอดที่จะจ่ายของคู่บนเชน TPIX มาจาก router อยู่แล้ว ราคานี้ใช้แสดงผล/ปุ่ม %)
      context.read<MarketProvider>().loadTpixPrice(retries: 1);
      // ที่อยู่สัญญา DEX มาจากเซิร์ฟเวอร์ — โหลดก่อนตัดสินว่าคู่ TPIX เทรดได้ไหม
      _dexService.loadConfig().then((_) {
        if (!mounted) return;
        setState(() => _dexConfigLoaded = true);
        _maybeReloadDexBalances(
            context.read<MarketProvider>(), context.read<WalletProvider>());
        // จำนวนที่กรอกไว้ก่อนทะเบียนสัญญาโหลดเสร็จ — ขอ quote ใหม่ด้วยเส้นทางที่ถูก
        _schedulePreview();
      });
    });
  }

  void _rebuildOnInputChange() {
    if (mounted) setState(() {});
    _schedulePreview();
  }

  // ── เทรดจริงบน BSC — helpers ──

  String _baseOf(MarketProvider market) =>
      market.selectedTicker?.baseAsset ??
      CryptoLogos.baseSymbol(market.selectedPair);

  String _quoteOf(MarketProvider market) {
    final t = market.selectedTicker;
    if (t != null && t.quoteAsset.isNotEmpty) return t.quoteAsset;
    final parts = market.selectedPair.split('-');
    return parts.length > 1 ? parts[1] : 'USDT';
  }

  bool _isTpixPairNow(MarketProvider market) =>
      CryptoLogos.isTpix(_baseOf(market));

  /// คู่บนเชน TPIX (TPIX-USDT หรือเหรียญลูกจาก Token Factory เช่น IDTC-USDT)
  bool _isChainPairNow(MarketProvider market) =>
      _isTpixPairNow(market) || market.isDexPair(market.selectedPair);

  /// สถานะของคู่นี้สำหรับการส่งเข้า PancakeSwap — ทะเบียนเหรียญในแอป **และ** ทะเบียนคู่บนเซิร์ฟเวอร์
  /// (เชน 56 · execution_mode = onchain · เปิดอยู่ · ที่อยู่เหรียญตรงกัน — กติกาเดียวกับเว็บ)
  /// คู่บนเชน TPIX ไม่นับ แม้ชื่อจะบังเอิญตรงกับเหรียญบน BSC
  ///
  /// ⚠️ เดิมดูแค่ทะเบียนในแอป — แอดมินปิดคู่บน BSC ฉุกเฉิน แอปก็ยังส่งเข้า PancakeSwap ได้
  BscPairGate _bscGate(MarketProvider market) {
    if (_isChainPairNow(market)) return BscPairGate.notInRegistry;
    final config = context.read<ConfigProvider>();
    return bscPairGate(
      baseSymbol: _baseOf(market),
      quoteSymbol: _quoteOf(market),
      serverPair: config.pairBySymbol(market.selectedPair),
      pairsLoaded: config.pairsLoaded,
      pairsLoadFailed: config.pairsLoadFailed,
    );
  }

  /// คู่นี้ execute จริงบน BSC ได้ไหม (เซิร์ฟเวอร์ยืนยันแล้ว)
  bool _isBscTradableNow(MarketProvider market) =>
      _bscGate(market) == BscPairGate.tradable;

  /// ยังตัดสินไม่ได้ว่าคู่ BSC นี้เปิดอยู่ไหม (กำลังโหลด/โหลดทะเบียนคู่ไม่สำเร็จ)
  /// — โชว์ฟอร์มไว้แต่ส่งไม่ได้ พร้อมเหตุผล (ไม่กระพริบเป็น Coming Soon)
  bool _isBscPendingGate(BscPairGate gate) =>
      gate == BscPairGate.checking || gate == BscPairGate.checkFailed;

  /// เหตุที่ยังส่งคำสั่งของคู่ BSC นี้ไม่ได้ — null = ส่งได้
  String? _bscGateReason(BscPairGate gate, bool th) => switch (gate) {
        BscPairGate.checking =>
          th ? 'กำลังตรวจสถานะคู่เทรด…' : "Checking this pair's status…",
        BscPairGate.checkFailed => th
            ? 'ตรวจสถานะคู่เทรดไม่สำเร็จ — แตะ "ลองใหม่" แล้วลองอีกครั้ง'
            : "Couldn't check this pair's status — tap Retry and try again.",
        BscPairGate.unavailable => th
            ? 'คู่นี้ยังไม่เปิดให้เทรดในขณะนี้'
            : "This pair isn't open for trading right now.",
        BscPairGate.tradable || BscPairGate.notInRegistry => null,
      };

  /// กุญแจของไม้ที่ฟอร์มแสดงอยู่ — เปลี่ยนคู่/ฝั่ง/จำนวน/slippage/กระเป๋า = quote เดิมใช้ผูกไม่ได้
  String _orderKey(MarketProvider market, WalletProvider wallet) =>
      '${market.selectedPair}|${_isBuy ? 'buy' : 'sell'}|${_amountController.text.trim()}|${wallet.slippage}|${wallet.address ?? ''}';

  /// ยอดที่จะจ่ายเข้า (input) ของไม้ BSC ตามฟอร์มตอนนี้ — null = จำนวน/ราคาใช้ไม่ได้
  ///
  /// sell: จ่าย base ตามจำนวนที่กรอก (คืนหน่วยเล็กสุดแบบตรงตัวจากข้อความด้วย)
  /// buy: จ่าย quote = ยอดจากปุ่ม % (ถ้าจำนวนยังเป็นค่าที่ปุ่มเติมให้) ไม่งั้น จำนวน × ราคาตลาด
  ({double amount, bool fromQuick})? _bscInput(
      MarketProvider market, WalletProvider wallet) {
    final amount = double.tryParse(_amountController.text.trim());
    if (amount == null || !(amount > 0)) return null;
    if (!_isBuy) return (amount: amount, fromQuick: false);
    final quick = _quickSpend;
    if (quick != null && quick > 0 && _quickSpendKey == _orderKey(market, wallet)) {
      return (amount: quick, fromQuick: true);
    }
    final price = market.selectedTicker?.lastPrice ?? 0;
    if (!(price > 0)) return null;
    return (amount: amount * price, fromQuick: false);
  }

  /// ป้ายใต้ชื่อคู่ — ไม้ของคู่นี้จะลงที่ไหน
  String _venueLabel(MarketProvider market, bool th) {
    final gate = _bscGate(market);
    if (gate == BscPairGate.tradable || _isBscPendingGate(gate)) {
      return 'BSC · PancakeSwap';
    }
    // คู่ในทะเบียน BSC ที่เซิร์ฟเวอร์ปิด/ไม่ได้เปิดบน BSC — ห้ามบอกว่า "TPIX Chain"
    if (gate == BscPairGate.unavailable) {
      return th ? 'BSC · ปิดชั่วคราว' : 'BSC · Paused';
    }
    return _isDexTradableNow(market)
        ? 'TPIX Chain · TPIX DEX'
        : 'TPIX Chain · Coming soon';
  }

  /// คู่นี้อยู่บนเชน TPIX และส่งคำสั่งได้จริงไหม
  ///
  /// ถามเซิร์ฟเวอร์ล้วน ๆ — ไม่เดาจากชื่อคู่เหมือนเดิม เพราะคู่บนเชน TPIX
  /// เกิดจากพูลที่มีคนเติมจริง (dex:sync สร้างให้) แอปจึงรู้ล่วงหน้าไม่ได้
  /// ต้องเข้าเงื่อนไขครบสามข้อ: เป็นเชน 4289 · execution_mode = onchain ·
  /// เซิร์ฟเวอร์ยืนยันว่าสัญญา DEX มีโค้ดอยู่บนเชนจริง
  TradingPairInfo? _dexPairFor(MarketProvider market) {
    if (!_dexService.ready) return null;
    final symbol = market.selectedPair.toUpperCase();
    // ชื่อนี้ต้องเป็นคู่บนเชนจริง ๆ — ชื่อที่ชนกับคู่ตลาดหลัก (เหรียญปลอมชื่อ SUI บนเชน TPIX)
    // ห้ามพาไปเทรดบน DEX เด็ดขาด แม้ /market/pairs จะมีคู่ onchain ชื่อเดียวกัน
    if (!market.isDexPair(symbol)) return null;
    final config = context.read<ConfigProvider>();
    // ที่อยู่ต้องตรงกับคู่ที่ตลาดตรวจแล้วจาก /dex/pairs (กันคู่ชื่อซ้ำที่เป็นเหรียญอื่น)
    final verified = market.dexPairInfo(symbol);
    bool sameLegs(TradingPairInfo p) {
      if (verified == null) return symbol == 'TPIX-USDT';
      return (p.baseAddress ?? '').toLowerCase() ==
              verified.baseAddress.toLowerCase() &&
          (p.quoteAddress ?? '').toLowerCase() ==
              verified.quoteAddress.toLowerCase();
    }

    for (final p in config.pairs) {
      if (p.symbol.toUpperCase() == symbol &&
          p.networkChainId == TpixDexService().lastConfig.chainId &&
          p.isOnchain &&
          sameLegs(p) &&
          (symbol != 'TPIX-USDT' ||
              (TpixDexService.isNative(p.baseAddress ?? '') &&
                  (p.quoteAddress ?? '').toLowerCase() ==
                      (_dexService.lastConfig.usdt ?? '').toLowerCase()))) {
        return p;
      }
    }
    // คู่ที่เพิ่งเปิดหลังแอปโหลดรายการคู่ (/market/pairs แคชไว้ตั้งแต่ splash)
    // — ใช้ /dex/pairs ที่ตลาดเพิ่งโหลด (เฉพาะคู่ที่เปิดเทรด ชื่อ/ที่อยู่ผ่านการตรวจแล้ว)
    final dex = market.dexPairInfo(symbol);
    if (dex != null && dex.chainId == TpixDexService().lastConfig.chainId) {
      return TradingPairInfo(
        symbol: dex.symbol,
        baseAsset: dex.baseAsset,
        quoteAsset: dex.quoteAsset,
        minTradeAmount: 0,
        maxTradeAmount: 0,
        pricePrecision: 8,
        amountPrecision: 4,
        baseLogo: dex.baseLogo,
        networkChainId: dex.chainId,
        executionMode: 'onchain',
        baseAddress: dex.baseAddress,
        quoteAddress: dex.quoteAddress,
        baseDecimals: dex.baseDecimals,
        quoteDecimals: dex.quoteDecimals,
        dexPairAddress: dex.pairAddress,
      );
    }
    return null;
  }

  bool _isDexTradableNow(MarketProvider market) =>
      _dexPairFor(market) != null;

  /// โลโก้ของ base — คู่บนเชน TPIX: token list → เซิร์ฟเวอร์ → ตัวอักษร (ไม่เดาจากชื่อ)
  String? _logoFor(MarketProvider market, String symbol) =>
      market.isDexPair(symbol)
          ? market.dexBaseLogo(symbol)
          : context.read<ConfigProvider>().pairBySymbol(symbol)?.baseLogo;

  /// โหลดยอด base/quote จากเชน TPIX — เรียกซ้ำได้ (กันโหลดซ้ำด้วย key)
  void _maybeReloadDexBalances(MarketProvider market, WalletProvider wallet) {
    if (!_isDexTradableNow(market)) return;
    final key = '${market.selectedPair}|${wallet.address ?? ''}';
    if (key == _dexBalanceKey) return;
    _dexBalanceKey = key;
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadDexBalances());
  }

  /// คู่/กระเป๋าของยอดบนเชน TPIX ที่ฟอร์มควรแสดงตอนนี้
  String _dexKeyNow(MarketProvider market, WalletProvider wallet) =>
      '${market.selectedPair}|${wallet.address ?? ''}';

  Future<void> _loadDexBalances() async {
    if (!mounted) return;
    final market = context.read<MarketProvider>();
    final wallet = context.read<WalletProvider>();
    final pair = _dexPairFor(market);
    if (!wallet.isConnected || pair == null) {
      if (mounted) {
        setState(() {
          _dexBaseBalance = null;
          _dexQuoteBalance = null;
          _dexBalancesFor = null;
        });
      }
      return;
    }
    final address = wallet.address!;
    final key = _dexKeyNow(market, wallet);
    // decimals ต้องมาจากเชน (ค่าจาก API ผิดทีเดียวยอดเพี้ยนเป็นล้านเท่า) — ตรวจไม่ได้ใช้ค่าจาก API
    var baseDec = pair.baseDecimals;
    var quoteDec = pair.quoteDecimals;
    try {
      final (b, q) = await _dexTokens(pair, market);
      baseDec = b.decimals;
      quoteDec = q.decimals;
    } catch (_) {}
    final results = await Future.wait([
      _dexService.getBalanceWei(
        address: pair.baseAddress ?? TpixDexService.nativeAddress,
        walletAddress: address,
      ),
      _dexService.getBalanceWei(
        address: pair.quoteAddress ?? TpixDexService.nativeAddress,
        walletAddress: address,
      ),
    ]);
    if (!mounted) return;
    // เปลี่ยนคู่/กระเป๋าระหว่างรอ — ผลนี้เป็นของอันเก่า ทิ้งไป
    if (_dexKeyNow(context.read<MarketProvider>(),
            context.read<WalletProvider>()) !=
        key) {
      return;
    }
    final same = _dexBalancesFor == key;
    setState(() {
      // อ่านพลาด (null) = คงยอดเดิมของคู่/กระเป๋านี้ ไม่ใช่ตั้งเป็น 0
      _dexBaseBalanceWei = results[0] ?? (same ? _dexBaseBalanceWei : null);
      _dexQuoteBalanceWei = results[1] ?? (same ? _dexQuoteBalanceWei : null);
      _dexBaseDecimals = baseDec;
      _dexBaseBalance = _dexBaseBalanceWei == null
          ? null
          : TpixDexService.fromWei(_dexBaseBalanceWei!, baseDec);
      _dexQuoteBalance = _dexQuoteBalanceWei == null
          ? null
          : TpixDexService.fromWei(_dexQuoteBalanceWei!, quoteDec);
      _dexBalancesFor = key;
    });
  }

  /// โทเคนสองฝั่งของคู่บนเชน TPIX — decimals ยืนยันกับเชนก่อนใช้เสมอ
  Future<(VerifiedToken, VerifiedToken)> _dexTokens(
      TradingPairInfo pair, MarketProvider market) async {
    final base = await _dexService.verifyToken(
      symbol: _baseOf(market),
      address: pair.baseAddress ?? TpixDexService.nativeAddress,
      fallbackDecimals: pair.baseDecimals,
    );
    final quote = await _dexService.verifyToken(
      symbol: _quoteOf(market),
      address: pair.quoteAddress ?? TpixDexService.nativeAddress,
      fallbackDecimals: pair.quoteDecimals,
    );
    return (base, quote);
  }

  /// โหลดยอด base/quote จาก BSC RPC — เรียกซ้ำได้ (กันโหลดซ้ำด้วย key)
  void _maybeReloadBscBalances(MarketProvider market, WalletProvider wallet) {
    if (!_isBscTradableNow(market)) return;
    final key = '${market.selectedPair}|${wallet.address ?? ''}';
    if (key == _bscBalanceKey) return;
    _bscBalanceKey = key;
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadBscBalances());
  }

  Future<void> _loadBscBalances() async {
    final market = context.read<MarketProvider>();
    final wallet = context.read<WalletProvider>();
    if (!wallet.isConnected || !_isBscTradableNow(market)) {
      if (mounted) {
        setState(() {
          _bscBaseBalance = null;
          _bscQuoteBalance = null;
        });
      }
      return;
    }
    final base = _baseOf(market);
    final quote = _quoteOf(market);
    final address = wallet.address!;
    final results = await Future.wait([
      _swapService.getBalance(base, address),
      _swapService.getBalance(quote, address),
    ]);
    if (!mounted) return;
    setState(() {
      _bscBaseBalance = results[0];
      _bscQuoteBalance = results[1];
    });
  }

  /// ขอ quote จริงจาก router แบบ debounce (ตามที่ผู้ใช้พิมพ์จำนวน)
  void _schedulePreview() {
    _previewDebounce?.cancel();
    _previewDebounce =
        Timer(const Duration(milliseconds: 500), _refreshPreview);
  }

  Future<void> _refreshPreview() async {
    if (!mounted) return;
    final market = context.read<MarketProvider>();
    final wallet = context.read<WalletProvider>();

    final dexPair = _dexPairFor(market);
    final onchain = _isBscTradableNow(market) || dexPair != null;

    void clearPreview() {
      // คำขอที่ค้างอยู่ห้ามมาเขียนทับหลังล้าง — ขยับลำดับไปด้วย
      _previewSeq++;
      if (_marketQuote != null ||
          _dexQuote != null ||
          _dexQuoteError != null ||
          _bscQuoteError != null ||
          _previewKey != null ||
          _quotingPreview) {
        setState(() {
          _marketQuote = null;
          _dexQuote = null;
          _dexQuoteError = null;
          _bscQuoteError = null;
          _previewKey = null;
          _quotingPreview = false;
        });
      }
    }

    if (!onchain || !_isMarket) {
      clearPreview();
      return;
    }

    final amount = double.tryParse(_amountController.text.trim()) ?? 0;
    // buy บน BSC: input จริงคือยอด quote (USDT) = ยอดจากปุ่ม % หรือ amount × ราคาตลาด
    // buy บนเชน TPIX: ให้ router คิดยอดที่ต้องจ่ายเอง (getAmountsIn) ไม่พึ่งราคา ticker
    // ที่อาจยังเป็น 0 ตอนเพิ่งเปิดแอป
    final bscInput = dexPair != null ? null : _bscInput(market, wallet);
    final input = dexPair != null ? amount : (bscInput?.amount ?? 0);
    if (amount <= 0 || input <= 0) {
      clearPreview();
      return;
    }

    final seq = ++_previewSeq;
    final isBuy = _isBuy;
    final th = context.read<LocaleProvider>().isThai;
    final amountText = _amountController.text;
    final orderKey = _orderKey(market, wallet);
    setState(() => _quotingPreview = true);
    try {
      if (dexPair != null) {
        final (baseTok, quoteTok) = await _dexTokens(dexPair, market);
        // จำนวน (หน่วย base) แปลงจากข้อความตรงตัว — ไม่ผ่าน double
        final amountWei = TpixDexService.parseUnits(amountText, baseTok.decimals);
        if (amountWei == null || amountWei <= BigInt.zero) {
          throw const SwapException('Amount is too small.', 'จำนวนน้อยเกินไป');
        }
        final quote = isBuy
            ? await _dexService.getQuoteForOutput(
                fromToken: quoteTok,
                toToken: baseTok,
                amountOut: amount,
                amountOutWei: amountWei,
                slippage: wallet.slippage,
              )
            : await _dexService.getQuote(
                fromToken: baseTok,
                toToken: quoteTok,
                amount: amount,
                amountInWei: amountWei,
                slippage: wallet.slippage,
              );
        if (!mounted || seq != _previewSeq) return;
        setState(() {
          _dexQuote = quote;
          _dexQuoteError = null;
          _bscQuoteError = null;
          _marketQuote = null;
          _previewKey = orderKey;
          _quotingPreview = false;
        });
        return;
      }

      final fromSym = isBuy ? _quoteOf(market) : _baseOf(market);
      final toSym = isBuy ? _baseOf(market) : _quoteOf(market);
      final quote = await _swapService.getQuote(
        fromSymbol: fromSym,
        toSymbol: toSym,
        amount: input,
        slippageOverride: wallet.slippage,
        // ขาย: จำนวน base ตามที่พิมพ์ตรงตัว (ตัวเดียวกับที่จะส่งจริง)
        amountText: isBuy ? null : amountText.trim(),
      );
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _marketQuote = quote;
        _dexQuote = null;
        _dexQuoteError = null;
        _bscQuoteError = null;
        _previewKey = orderKey;
        _quotingPreview = false;
      });
    } on SwapException catch (e) {
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _marketQuote = null;
        _dexQuote = null;
        _previewKey = null;
        // บอกเหตุผลก่อนกด — เชน TPIX: "ใหญ่เกินพูล สั่งได้สูงสุด ..." ·
        // BSC: "คู่นี้ปิดการเทรดอยู่" / "ตรวจค่าธรรมเนียมไม่สำเร็จ" / "ไม่มีสภาพคล่อง"
        _dexQuoteError = dexPair != null ? e.message(th) : null;
        _bscQuoteError = dexPair == null ? e.message(th) : null;
        _quotingPreview = false;
      });
    } catch (_) {
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _marketQuote = null;
        _dexQuote = null;
        _dexQuoteError = null;
        _bscQuoteError = null;
        _previewKey = null;
        _quotingPreview = false;
      });
    }
  }

  /// สลับซื้อ/ขาย — quote เดิมเป็นของอีกฝั่ง (คนละทิศ คนละหน่วย) ต้องขอใหม่
  /// เดิมไม่ขอใหม่ → ตัวเลข "จะได้รับ" และ price impact ค้างเป็นของฝั่งก่อนหน้า
  void _setSide(bool buy) {
    if (_isBuy == buy) return;
    setState(() {
      _isBuy = buy;
      _dexQuote = null;
      _marketQuote = null;
      _dexQuoteError = null;
      _bscQuoteError = null;
      _previewKey = null;
      _quickSpend = null;
      _quickSpendKey = null;
    });
    _schedulePreview();
  }

  /// สลับแท็บ Limit / Market — rebuild เพื่อสลับระหว่างฟอร์มกับคำอธิบาย แล้วขอ quote ใหม่
  /// Guard: TabController fires 2x (indexIsChanging + indexChanged) — รันตอน settled เท่านั้น
  void _handleOrderTypeChange() {
    if (!mounted) return;
    if (_orderTypeTab.indexIsChanging) return; // รอ animation เสร็จก่อน
    setState(() {});
    _schedulePreview();
  }

  @override
  void dispose() {
    _previewDebounce?.cancel();
    _orderTypeTab.removeListener(_handleOrderTypeChange);
    _amountController.removeListener(_rebuildOnInputChange);
    _orderTypeTab.dispose();
    _amountController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final market = context.watch<MarketProvider>();
    final wallet = context.watch<WalletProvider>();
    // ทะเบียนคู่จากเซิร์ฟเวอร์ตัดสินว่าคู่ BSC ส่งคำสั่งได้ไหม — โหลดเสร็จ/เปลี่ยนต้อง rebuild
    context.watch<ConfigProvider>();
    final ticker = market.selectedTicker;
    // คู่บนเชน TPIX ทุกคู่ (รวมเหรียญลูก) ใช้กราฟจาก /dex/klines ไม่ใช่ Binance
    final isTpix = _isChainPairNow(market);

    // คู่ major → โหลดยอดจาก BSC สำหรับฟอร์มเทรด (โหลดครั้งเดียวต่อ pair/wallet)
    _maybeReloadBscBalances(market, wallet);
    _maybeReloadDexBalances(market, wallet);

    // เปลี่ยนคู่แล้ว quote เดิมเป็นของคู่เก่า — ขอใหม่หลังเฟรม (สั่ง setState ใน build ไม่ได้)
    if (_previewPair != market.selectedPair) {
      _previewPair = market.selectedPair;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _schedulePreview();
      });
    }

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        // กราฟ WebView + ราคาสดวิ่งอยู่แล้ว — พื้นหลังนิ่ง ไม่แย่งเครื่องถอดรหัสวิดีโอ
        animated: false,
        child: SafeArea(
          bottom: false,
          child: Column(
            children: [
              _buildPairHeader(market, ticker, locale),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.only(bottom: 110),
                  child: Column(
                    children: [
                      // Gilded chart card — gold timeframe pills + chart widget
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
                        child: _buildChartCard(market, locale, isTpix),
                      ),

                      const SizedBox(height: 14),

                      // Order book
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: _buildOrderBook(market, locale),
                      ),

                      const SizedBox(height: 16),

                      // คู่ TPIX-USDT — ทางลัดซื้อ TPIX ด้วยบัตร (หน้า token sale บนเว็บ)
                      if (market.selectedPair == 'TPIX-USDT')
                        const Padding(
                          padding: EdgeInsets.fromLTRB(16, 0, 16, 12),
                          child: BuyTpixCard(compact: true),
                        ),

                      // Trade form
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: _buildTradeForm(locale, wallet, market),
                      ),

                      // Open Orders — ทุกคู่ (รวมคำสั่งตั้งราคาที่วางจากหน้าเว็บ)
                      if (wallet.isConnected && wallet.openOrders.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                          child: _buildOpenOrders(wallet, locale),
                        ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Pair header ──

  Widget _buildPairHeader(
      MarketProvider market, Ticker? ticker, LocaleProvider locale) {
    final isFav =
        ticker != null && market.favorites.contains(ticker.symbol);

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 6),
      child: Row(
        children: [
          GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: () => _showPairPicker(market),
            child: Row(
              children: [
                CoinChip(
                  symbol: ticker?.baseAsset ??
                      CryptoLogos.baseSymbol(market.selectedPair),
                  size: 40,
                  logoUrl: _logoFor(market, market.selectedPair),
                  chainToken: market.isDexPair(market.selectedPair),
                ),
                const SizedBox(width: 11),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Text(
                          ticker?.displaySymbol ?? market.selectedPair,
                          style: AppFonts.ui(
                            fontSize: 19,
                            fontWeight: FontWeight.w800,
                            color: AppColors.textPrimary,
                            letterSpacing: -0.3,
                          ),
                        ),
                        const SizedBox(width: 4),
                        Icon(Icons.keyboard_arrow_down_rounded,
                            color: AppColors.textTertiary, size: 20),
                      ],
                    ),
                    const SizedBox(height: 1),
                    // คู่ major เทรดจริงบน BSC — คู่บนเชน TPIX เทรดผ่าน TPIX DEX เมื่อมีพูล
                    Text(
                      _venueLabel(market, locale.isThai) +
                          // ราคา TPIX ที่เซิร์ฟเวอร์ตั้งไว้เอง (ไม่มีพูลให้อ่าน) — บอกตรง ๆ
                          (_isTpixPairNow(market) &&
                                  market.selectedPair == 'TPIX-USDT' &&
                                  market.tpixPriceIsIndicative
                              ? (locale.isThai
                                  ? ' · ราคาอ้างอิง'
                                  : ' · Indicative price')
                              : ''),
                      style: AppFonts.ui(
                        fontSize: 10.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textTertiary,
                        letterSpacing: 0.4,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          // Favorite star (real — MarketProvider.toggleFavorite)
          if (ticker != null)
            GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => market.toggleFavorite(ticker.symbol),
              child: Padding(
                padding: const EdgeInsets.all(4),
                child: Icon(
                  isFav ? Icons.star_rounded : Icons.star_border_rounded,
                  size: 22,
                  color: isFav
                      ? context.watch<AccentProvider>().g2
                      : AppColors.textTertiary,
                ),
              ),
            ),
          const Spacer(),
          if (ticker != null)
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                PriceText(
                  price: ticker.lastPrice,
                  change: ticker.priceChangePercent,
                  fontSize: 17,
                  fontWeight: FontWeight.w700,
                ),
                const SizedBox(height: 3),
                ChangeBadge(changePercent: ticker.priceChangePercent),
              ],
            ),
        ],
      ),
    );
  }

  // ── ป้าย/เส้นของบอทบนกราฟ ──

  /// ประกอบป้ายเข้า/ออกไม้ + เส้นต้นทุน/SL/TP ของบอทในคู่นี้ แล้วส่งให้ WebView
  ///
  /// เรียกจาก build ทุกครั้ง แต่ยิงเข้ากราฟเฉพาะเมื่อชุดข้อมูลเปลี่ยนจริง (เทียบ JSON)
  /// และทำหลังเฟรม — สั่ง provider/WebView ระหว่าง build ไม่ได้
  void _syncBotOverlay(AiBotProvider aiBot, String pair, bool th) {
    final wanted = pair.replaceAll('-', '/').toUpperCase();
    final markers = <Map<String, dynamic>>[];
    final lines = <Map<String, dynamic>>[];

    if (_botMode) {
      for (final t in aiBot.tradesFor(wanted)) {
        final at = t.createdAt;
        if (at == null) continue;
        final isBuy = t.side.toLowerCase() == 'buy';
        final pnl = t.realizedPnl;
        final String label;
        if (!isBuy && pnl != null) {
          label = '${th ? 'บอทขาย' : 'Bot sell'} ${pnl >= 0 ? '+' : ''}${pnl.toStringAsFixed(2)}';
        } else {
          final word = th ? (isBuy ? 'บอทซื้อ' : 'บอทขาย') : (isBuy ? 'Bot buy' : 'Bot sell');
          label = t.grossValue > 0 ? '$word \$${t.grossValue.toStringAsFixed(0)}' : word;
        }
        markers.add({
          'time': at.toUtc().millisecondsSinceEpoch ~/ 1000,
          'side': isBuy ? 'buy' : 'sell',
          'source': 'bot',
          'label': label,
          'emphasize': true,
        });
      }

      // เส้นราคาจาก entry × (1 ∓ %) เหมือนที่ BotRunner ใช้ตัดสินจริง
      for (final b in aiBot.bots) {
        if (b.pair.replaceAll('-', '/').toUpperCase() != wanted) continue;
        final pos = b.position;
        if (pos == null || pos.entryPrice <= 0) continue;
        final entry = pos.entryPrice;
        lines.add({'price': entry, 'color': '#38bdf8', 'style': 'dashed', 'title': '${th ? 'ต้นทุนบอท' : 'Bot cost'} · ${b.name}'});
        if (b.risk.stopLossPct > 0) {
          lines.add({'price': entry * (1 - b.risk.stopLossPct / 100), 'color': '#FF1744', 'style': 'dotted', 'title': th ? 'SL บอท' : 'Bot SL'});
        }
        if (b.risk.takeProfitPct > 0) {
          lines.add({'price': entry * (1 + b.risk.takeProfitPct / 100), 'color': '#00C853', 'style': 'dotted', 'title': th ? 'TP บอท' : 'Bot TP'});
        }
      }
    }

    final markersJson = jsonEncode(markers);
    final linesJson = jsonEncode(lines);
    final pairChanged = _lastTradesPair != wanted;

    if (!pairChanged && markersJson == _lastMarkersJson && linesJson == _lastLinesJson) return;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (pairChanged) {
        _lastTradesPair = wanted;
        // ข้อมูลบอทอาจยังไม่เคยโหลดถ้าผู้ใช้ไม่ได้เปิดหน้า AI TRADE มาก่อน
        if (aiBot.isWalletConnected) {
          if (aiBot.status == null) unawaited(aiBot.bootstrap());
          unawaited(aiBot.loadTrades(wanted));
        }
      }
      if (markersJson != _lastMarkersJson) {
        _lastMarkersJson = markersJson;
        _chartKey.currentState?.setMarkers(markers);
      }
      if (linesJson != _lastLinesJson) {
        _lastLinesJson = linesJson;
        _chartKey.currentState?.setPriceLines(lines);
      }
    });
  }

  // ── Chart card (gilded hero) ──

  Widget _buildChartCard(
      MarketProvider market, LocaleProvider locale, bool isTpix) {
    _syncBotOverlay(context.watch<AiBotProvider>(), market.selectedPair, locale.isThai);

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: AppTheme.radiusHero,
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 14),
      child: Column(
        children: [
          // Timeframe pills + chart-type toggle row
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 30,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    padding: EdgeInsets.zero,
                    itemCount: _pillTimeframes.length,
                    separatorBuilder: (_, _) => const SizedBox(width: 6),
                    itemBuilder: (_, i) => _TimeframePill(
                      label: _pillLabels[i],
                      active: _timeframe == _pillTimeframes[i],
                      onTap: () {
                        if (_timeframe == _pillTimeframes[i]) return;
                        setState(() => _timeframe = _pillTimeframes[i]);
                        _chartKey.currentState
                            ?.changeTimeframe(_pillTimeframes[i]);
                      },
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              ChartTypeToggle(
                selected: _chartType,
                onChanged: (type) {
                  setState(() => _chartType = type);
                  _chartKey.currentState?.setChartType(type);
                },
              ),
              const SizedBox(width: 8),
              // โหมดบอท — เน้นป้ายเข้า/ออกไม้ของบอท + เส้นต้นทุน/SL/TP
              _TimeframePill(
                label: locale.isThai ? '🤖 บอท' : '🤖 Bot',
                active: _botMode,
                onTap: () => setState(() => _botMode = !_botMode),
              ),
            ],
          ),
          const SizedBox(height: 12),
          // TradingView Chart (WebView) — unchanged logic
          ClipRRect(
            borderRadius: BorderRadius.circular(AppTheme.radiusLg),
            child: TradingChart(
              key: _chartKey,
              symbol: market.selectedPair,
              interval: _timeframe,
              isTpix: isTpix,
              height: 300,
            ),
          ),
          if (_botMode) ...[
            const SizedBox(height: 8),
            Text(
              locale.isThai
                  ? '▲ บอทซื้อ · ▼ บอทขาย · เส้นประ = ต้นทุน / SL / TP'
                  : '▲ bot buy · ▼ bot sell · dashed = cost / SL / TP',
              style: AppFonts.ui(
                fontSize: 10,
                color: AppColors.textTertiary,
              ),
            ),
          ],
        ],
      ),
    );
  }

  // ── Order book ──

  Widget _buildOrderBook(MarketProvider market, LocaleProvider locale) {
    final ob = market.orderBook;

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: AppTheme.radiusLg,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.menu_rounded, size: 15, color: AppColors.gold2),
              const SizedBox(width: 7),
              Text(
                locale.t('trade.orderbook'),
                style: AppFonts.ui(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              // คู่บนเชน TPIX เป็น AMM — ตัวเลขคำนวณจาก reserve ของพูล ไม่ใช่ออร์เดอร์ที่มีคนวาง
              if (market.isDexPair(market.selectedPair)) ...[
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    locale.isThai
                        ? '· ความลึกพูล (คำนวณจาก reserve)'
                        : '· Pool depth (from reserves)',
                    overflow: TextOverflow.ellipsis,
                    style: AppFonts.ui(
                      fontSize: 10.5,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 12),

          // Header
          Row(
            children: [
              Expanded(
                child: Text(locale.t('trade.price'), style: _obHeaderStyle),
              ),
              Expanded(
                child: Text(locale.t('trade.amount'),
                    style: _obHeaderStyle, textAlign: TextAlign.right),
              ),
              Expanded(
                child: Text(locale.t('trade.total'),
                    style: _obHeaderStyle, textAlign: TextAlign.right),
              ),
            ],
          ),
          const SizedBox(height: 6),

          // Asks (sell) — top 5, depth bars grow from the right
          if (ob != null)
            ...ob.asks.take(5).toList().reversed.map(
                  (entry) => _OrderBookRow(
                    entry: entry,
                    isBid: false,
                    maxQty: _maxQty(ob.asks),
                  ),
                ),

          // Spread — gold line in the middle
          if (ob != null && ob.asks.isNotEmpty && ob.bids.isNotEmpty)
            Container(
              margin: const EdgeInsets.symmetric(vertical: 6),
              padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 10),
              decoration: BoxDecoration(
                color: AppColors.goldTint,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: AppColors.goldBorder,
                  width: 0.8,
                ),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    locale.t('common.spread'),
                    style: AppFonts.ui(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: AppColors.gold2,
                      letterSpacing: 0.4,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    (ob.asks.first.price - ob.bids.first.price)
                        .toStringAsFixed(2),
                    style: AppTheme.mono(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.gold2,
                    ),
                  ),
                ],
              ),
            ),

          // Bids (buy) — top 5, depth bars grow from the left
          if (ob != null)
            ...ob.bids.take(5).map(
                  (entry) => _OrderBookRow(
                    entry: entry,
                    isBid: true,
                    maxQty: _maxQty(ob.bids),
                  ),
                ),

          if (ob == null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 20),
              child: Center(
                child: Text(locale.t('common.loading'),
                    style: TextStyle(
                        color: AppColors.textTertiary, fontSize: 12)),
              ),
            ),
        ],
      ),
    );
  }

  double _maxQty(List<OrderBookEntry> entries) {
    if (entries.isEmpty) return 1;
    return entries
        .take(5)
        .fold(0.0, (max, e) => e.quantity > max ? e.quantity : max);
  }

  TextStyle get _obHeaderStyle => AppFonts.ui(
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: AppColors.textTertiary,
        letterSpacing: 0.3,
      );

  // ── Trade form ──

  Widget _buildTradeForm(
      LocaleProvider locale, WalletProvider wallet, MarketProvider market) {
    // ไม่มีแถว ticker (คู่เพิ่งเปิด/รายการยังโหลดไม่เสร็จ) = ใช้ชื่อจากคู่ที่เลือก
    // เดิมตกไปเป็น 'BTC' → ปุ่มขึ้น "ซื้อ BTC" ทั้งที่เลือกคู่ IDTC อยู่
    final baseAsset = _baseOf(market);
    final quoteAsset = _quoteOf(market);
    final onDex = _isDexTradableNow(market);
    final gate = _bscGate(market);
    // ระหว่างรอทะเบียนคู่ของคู่ BSC = โชว์ฟอร์มไว้แต่ปุ่มปิด พร้อมเหตุผล (ไม่กระพริบเป็น Coming Soon)
    final bscPending = _isBscPendingGate(gate);
    final gateReason = onDex ? null : _bscGateReason(gate, locale.isThai);
    final tradable = gate == BscPairGate.tradable || onDex || bscPending;

    // คู่ที่ยังเทรดจริงไม่ได้ (พูลบนเชน TPIX ยังไม่มี / คู่ไม่มี token บน BSC)
    // → โชว์ Coming Soon แทนฟอร์ม (เห็นไว้ก่อน กดไม่ได้ — ตรงกับเว็บ)
    if (!tradable) {
      // คู่ในทะเบียน BSC ที่เซิร์ฟเวอร์ปิด / ไม่ได้เปิดบน BSC — บอกตรง ๆ ว่าปิด ไม่ใช่ "รอเชน TPIX"
      if (gate == BscPairGate.unavailable) {
        return _buildComingSoonCard(locale, market, paused: true);
      }
      // ยังไม่รู้ว่าเชนเราเปิดหรือยัง (ทะเบียนสัญญายังโหลดไม่เสร็จ) — อย่าเพิ่งตัดสิน
      // ไม่งั้นคนเปิดหน้ามาจะเห็น "ยังไม่เปิด" แว้บหนึ่งทั้งที่กระดานเปิดอยู่จริง
      return _buildComingSoonCard(locale, market,
          checking: !_dexConfigLoaded && _isChainPairNow(market));
    }

    // Available balance — อ่านยอดจริงจากเชนที่ไม้จะลงจริง
    // (ไม่ใช่ portfolio off-chain เดิม เพราะเทรด settle บนเชนจริง)
    final availAsset = _isBuy ? quoteAsset : baseAsset;
    // ยอดของคู่/กระเป๋าอื่นที่ยังค้างอยู่ระหว่างโหลดใหม่ = ไม่รู้ (ไม่ใช่ของคู่นี้)
    final dexBalancesValid = _dexBalancesFor == _dexKeyNow(market, wallet);
    final chainBalance = onDex
        ? (dexBalancesValid
            ? (_isBuy ? _dexQuoteBalance : _dexBaseBalance)
            : null)
        : (_isBuy ? _bscQuoteBalance : _bscBaseBalance);
    // null = ยังไม่รู้/อ่านไม่ได้ → แสดง — ไม่ใช่ 0 (0 ทำให้คนตกใจว่าเงินหาย)
    final availBalance = chainBalance ?? 0.0;

    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: AppTheme.radiusXl,
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          // Segmented Buy / Sell
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
                  child: _SideTab(
                    label: locale.t('trade.buy'),
                    active: _isBuy,
                    isBuy: true,
                    onTap: () => _setSide(true),
                  ),
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: _SideTab(
                    label: locale.t('trade.sell'),
                    active: !_isBuy,
                    isBuy: false,
                    onTap: () => _setSide(false),
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 14),

          // Order type tabs (Limit / Market) — แอปส่งได้เฉพาะ Market
          // แท็บ Limit เปิดดูได้: อธิบายตรง ๆ ว่าคำสั่งตั้งราคาวางที่หน้าเว็บ (ไม่เด้งกลับเงียบ ๆ)
          Container(
            decoration: BoxDecoration(
              color: AppColors.bgInputStrong,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.bgCardBorder),
            ),
            child: TabBar(
              controller: _orderTypeTab,
              indicator: BoxDecoration(
                color: AppColors.bgTertiary,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.goldBorder, width: 1),
              ),
              indicatorSize: TabBarIndicatorSize.tab,
              indicatorPadding: const EdgeInsets.all(3),
              labelColor: AppColors.gold1,
              unselectedLabelColor: AppColors.textTertiary,
              labelStyle:
                  AppFonts.ui(fontSize: 12, fontWeight: FontWeight.w700),
              unselectedLabelStyle:
                  AppFonts.ui(fontSize: 12, fontWeight: FontWeight.w500),
              dividerColor: Colors.transparent,
              tabs: [
                Tab(
                  height: 34,
                  child: _badgeTabLabel(
                      locale.t('trade.limit'), locale.isThai ? 'เว็บ' : 'Web'),
                ),
                Tab(text: locale.t('trade.market'), height: 34),
              ],
            ),
          ),

          const SizedBox(height: 14),

          if (!_isMarket)
            _buildLimitOnWebNotice(locale, market)
          else ...[
          // Available balance row
          Row(
            children: [
              Icon(Icons.account_balance_wallet_rounded,
                  size: 13, color: AppColors.textTertiary),
              const SizedBox(width: 6),
              Text(
                locale.isThai ? 'ยอดที่ใช้ได้' : 'Available',
                style: AppFonts.ui(
                    fontSize: 11.5, color: AppColors.textTertiary),
              ),
              const Spacer(),
              Text(
                wallet.isConnected && chainBalance != null
                    ? '${_fmtAmount(availBalance)} $availAsset'
                    : '—',
                style: AppTheme.mono(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),

          const SizedBox(height: 12),

          // Amount input
          _TradeInput(
            label: locale.t('trade.amount'),
            controller: _amountController,
            suffix: baseAsset,
          ),
          const SizedBox(height: 12),

          // Amount quick chips: 25/50/75/100% of available balance
          Row(
            children: ['25%', '50%', '75%', '100%'].map((pct) {
              final isLast = pct == '100%';
              return Expanded(
                child: Padding(
                  padding: EdgeInsets.only(right: isLast ? 0 : 8),
                  child: _QuickChip(
                    label: pct,
                    onTap: () => _applyQuickPercent(
                      pct,
                      wallet,
                      market,
                      availBalance,
                      onDex: onDex,
                    ),
                  ),
                ),
              );
            }).toList(),
          ),

          const SizedBox(height: 12),

          // Market preview — ตัวเลขจริงจาก router (PancakeSwap / TPIX DEX)
          // (แทน fee summary เดิมเมื่อมี quote เพราะ fee ใน quote คือของจริง)
          if (gateReason != null)
            // ยังไม่รู้ว่าคู่นี้เปิดบน BSC ไหม — บอกเหตุ + ให้ลองใหม่ (ปุ่มส่งถูกปิดอยู่)
            _buildGateNotice(
              gateReason,
              locale,
              onRetry: gate == BscPairGate.checkFailed
                  ? () => context.read<ConfigProvider>().reloadPairs()
                  : null,
            )
          else if (onDex && _dexQuoteError != null && !_quotingPreview)
            _buildDexNotice(_dexQuoteError!)
          else if (!onDex && _bscQuoteError != null && !_quotingPreview)
            // BSC: คู่ถูกปิด / ตรวจค่าธรรมเนียมไม่ได้ / ไม่มีสภาพคล่อง — บอกก่อนกด
            _buildDexNotice(_bscQuoteError!)
          else if (_marketQuote != null || _dexQuote != null || _quotingPreview)
            _buildMarketPreview(locale, market)
          else
            _buildFeeSummary(locale, market),

          const SizedBox(height: 14),

          // Submit CTA — green for Buy, red for Sell
          GradientButton(
            text: _isBuy
                ? '${locale.t('trade.buy')} $baseAsset'
                : '${locale.t('trade.sell')} $baseAsset',
            variant: _isBuy ? ButtonVariant.buy : ButtonVariant.sell,
            isLoading: _isSubmitting,
            // ยังตัดสินไม่ได้ว่าคู่เปิดอยู่ไหม = ปิดปุ่ม (เหตุผลอยู่ในแถบด้านบน)
            onPressed: wallet.isConnected && !_isSubmitting && !bscPending
                ? () => _submitOrder()
                : null,
          ),

          // แถบบอกว่าเทรดจริงบน BSC — ให้ผู้ใช้รู้ว่าเหรียญเข้ากระเป๋าจริง
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.bolt_rounded, size: 12, color: AppColors.gold2),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  onDex
                      ? (locale.isThai
                          ? 'คำสั่ง Market ทำงานจริงบนเชน TPIX ผ่าน TPIX DEX'
                          : 'Market orders execute for real on TPIX Chain via TPIX DEX')
                      : (locale.isThai
                          ? 'คำสั่ง Market ทำงานจริงบน BSC ผ่าน PancakeSwap'
                          : 'Market orders execute for real on BSC via PancakeSwap'),
                  style: AppFonts.ui(
                      fontSize: 10, color: AppColors.textTertiary),
                  textAlign: TextAlign.center,
                ),
              ),
            ],
          ),

          // Linked wallet — เทรดผ่าน TPIX Wallet: แอพจะสลับไปให้ยืนยัน
          // แต่ละธุรกรรม (approve → swap → fee) แล้วเด้งกลับมาเอง
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
                      locale.isThai
                          ? 'จะเปิด TPIX Wallet ให้ยืนยันธุรกรรมทีละรายการ แล้วกลับมาที่นี่อัตโนมัติ'
                          : 'TPIX Wallet will open to confirm each transaction, then return here automatically.',
                      style: AppFonts.ui(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],

          if (!wallet.isConnected) ...[
            const SizedBox(height: 10),
            Text(
              locale.t('settings.connect_wallet'),
              style: AppFonts.ui(
                  fontSize: 12, color: AppColors.textTertiary),
            ),
          ],

          // Linked-wallet hint — แจ้ง user ว่า submit จะส่ง sign request ไป
          // TPIX Wallet (กัน user งงว่าทำไมแอพสลับไป-มา)
          if (wallet.isConnected &&
              wallet.isLinkedWallet &&
              !wallet.isVerified) ...[
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
                  Icon(Icons.info_outline_rounded,
                      size: 14, color: AppColors.gold2),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      locale.isThai
                          ? 'ครั้งแรก: แอพจะเปิด TPIX Wallet ให้เซ็นยืนยันตัวตน'
                          : 'First time: TPIX Wallet will open to confirm your identity',
                      style: AppFonts.ui(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          ], // จบส่วนฟอร์ม Market
        ],
      ),
    );
  }

  /// แท็บ Limit — บอกตามจริง: คำสั่งตั้งราคาวางที่หน้าเว็บ แอปนี้ยังวางไม่ได้
  /// (เดิมมีแท็บ Limit/Stop-limit ที่กดแล้วเด้งกลับ Market พร้อมข้อความว่า "เปิดพร้อม TPIX Chain"
  /// ทั้งที่หน้าเว็บวางคำสั่งตั้งราคาได้แล้ว)
  Widget _buildLimitOnWebNotice(LocaleProvider locale, MarketProvider market) {
    final th = locale.isThai;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
      decoration: BoxDecoration(
        color: AppColors.goldTint,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.goldBorder, width: 0.8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.info_outline_rounded,
                  size: 15, color: AppColors.gold2),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  th
                      ? 'คำสั่งตั้งราคา (Limit) ตอนนี้วางได้ที่หน้าเว็บ tpix.online เท่านั้น — แอปนี้ยังวางไม่ได้'
                      : 'Limit orders can only be placed on the web (tpix.online) for now — this app cannot place them yet.',
                  style: AppFonts.ui(
                    fontSize: 12,
                    height: 1.45,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            th
                ? 'คำสั่งที่วางจากเว็บจะขึ้นใน "คำสั่งที่เปิดอยู่" ด้านล่าง และยกเลิกจากแอปได้ขณะยังรอราคา · ในแอปใช้ Market เพื่อซื้อขายทันที'
                : 'Orders placed on the web appear under Open Orders below, and you can cancel them here while they wait for the price. Use Market in the app to trade right away.',
            style: AppFonts.ui(
              fontSize: 11,
              height: 1.45,
              color: AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () => launchUrl(
                Uri.parse(
                    'https://tpix.online/trade/${Uri.encodeComponent(market.selectedPair)}'),
                mode: LaunchMode.externalApplication,
              ),
              style: TextButton.styleFrom(
                foregroundColor: AppColors.gold1,
                padding: const EdgeInsets.symmetric(horizontal: 8),
                minimumSize: const Size(0, 32),
              ),
              icon: const Icon(Icons.open_in_new_rounded, size: 14),
              label: Text(
                th ? 'เปิดหน้าเว็บ' : 'Open the web',
                style:
                    AppFonts.ui(fontSize: 12, fontWeight: FontWeight.w700),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// แถบ "ยังส่งคำสั่งของคู่นี้ไม่ได้" (กำลังตรวจ/ตรวจสถานะคู่ไม่สำเร็จ) พร้อมปุ่มลองใหม่
  Widget _buildGateNotice(String message, LocaleProvider locale,
      {VoidCallback? onRetry}) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
      decoration: BoxDecoration(
        color: AppColors.goldTint,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.goldBorder, width: 0.8),
      ),
      child: Row(
        children: [
          Icon(Icons.hourglass_top_rounded, size: 14, color: AppColors.gold2),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              message,
              style: AppFonts.ui(
                fontSize: 11,
                height: 1.4,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          if (onRetry != null)
            TextButton(
              onPressed: onRetry,
              style: TextButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                minimumSize: const Size(0, 30),
                foregroundColor: AppColors.gold1,
              ),
              child: Text(
                locale.isThai ? 'ลองใหม่' : 'Retry',
                style:
                    AppFonts.ui(fontSize: 11, fontWeight: FontWeight.w700),
              ),
            ),
        ],
      ),
    );
  }

  /// Quick-percent fill: prefer % of available balance; if balance unknown
  /// (not connected / zero), fall back to % of the current amount entry.
  void _applyQuickPercent(
    String pct,
    WalletProvider wallet,
    MarketProvider market,
    double availBalance, {
    bool onDex = false,
  }) {
    final factor = double.parse(pct.replaceAll('%', '')) / 100;

    if (wallet.isConnected && availBalance > 0) {
      // ซื้อบนเชน TPIX: แปลง USDT เป็นจำนวน TPIX ด้วย router (ไม่พึ่งราคา ticker ที่อาจยังเป็น 0)
      if (_isBuy && onDex && _isMarket) {
        final pctInt = (factor * 100).round();
        final quoteWei = _dexBalancesFor == _dexKeyNow(market, wallet)
            ? _dexQuoteBalanceWei
            : null;
        _quickFillDexBuy(
          availBalance * factor,
          market,
          wallet,
          spendWei: quoteWei == null
              ? null
              : quoteWei * BigInt.from(pctInt) ~/ BigInt.from(100),
        );
        return;
      }
      // ขายบนเชน TPIX: เติมจากยอดหน่วยเล็กสุดแบบตรงตัว (100% = ยอดทั้งหมดพอดี ไม่ปัดเกิน)
      final baseWei = _dexBalancesFor == _dexKeyNow(market, wallet)
          ? _dexBaseBalanceWei
          : null;
      if (!_isBuy && onDex && baseWei != null && _dexBaseDecimals != null) {
        final pctInt = (factor * 100).round();
        _amountController.text = TpixDexService.formatUnits(
            baseWei * BigInt.from(pctInt) ~/ BigInt.from(100),
            _dexBaseDecimals!);
        return;
      }
      // BSC — ตัดทศนิยมทิ้งเสมอ ห้ามปัดขึ้น (toStringAsFixed ปัดเข้าหาค่าใกล้สุด = เกินยอดจริงได้
      // → สวอปผ่านแต่ธุรกรรมโอนค่าธรรมเนียม revert) · ยอดจริงบนเชนถูกอ่านซ้ำก่อนส่งอีกชั้น
      // For Buy, available is in quote → convert to base amount via price.
      if (_isBuy) {
        final px = market.selectedTicker?.lastPrice ?? 0;
        // No usable price yet — leave amount untouched.
        if (!(px > 0)) return;
        final spend = double.tryParse(
                BscSwapService.floorDecimal(availBalance * factor, 8)) ??
            0;
        final baseText = BscSwapService.floorDecimal(spend / px, 8);
        if (!(spend > 0) || !((double.tryParse(baseText) ?? 0) > 0)) return;
        _amountController.text = baseText;
        // จำยอด quote ที่จะจ่ายไว้กับจำนวนนี้ — ตอนส่งใช้ยอดนี้ตรง ๆ ไม่คูณราคาตลาดใหม่
        // (ราคาขยับขึ้นนิดเดียว "จำนวน × ราคา" ก็เกินยอดในกระเป๋าแล้ว)
        _quickSpend = spend;
        _quickSpendKey = _orderKey(market, wallet);
        return;
      }
      // Sell → available already in base asset.
      _amountController.text =
          BscSwapService.floorDecimal(availBalance * factor, 8);
      return;
    }

    // Fallback: scale whatever the user already typed.
    final current = double.tryParse(_amountController.text) ?? 0;
    if (current > 0) {
      _amountController.text =
          BscSwapService.floorDecimal(current * factor, 8);
    }
  }

  /// เติมจำนวน TPIX ที่จะได้ถ้าจ่าย [spend] USDT — ถาม router ตรง ๆ
  /// ปัดลง 4 ตำแหน่ง (ปัดขึ้นแล้วยอดที่ต้องจ่ายจะเกินที่มี)
  Future<void> _quickFillDexBuy(
      double spend, MarketProvider market, WalletProvider wallet,
      {BigInt? spendWei}) async {
    final pair = _dexPairFor(market);
    if (pair == null || (spend <= 0 && spendWei == null)) return;
    if (spendWei != null && spendWei <= BigInt.zero) return;
    final seq = ++_quickFillSeq;
    final pairAtStart = market.selectedPair;
    try {
      final (baseTok, quoteTok) = await _dexTokens(pair, market);
      final q = await _dexService.getQuote(
        fromToken: quoteTok,
        toToken: baseTok,
        amount: spend,
        amountInWei: spendWei,
        slippage: wallet.slippage,
      );
      if (!mounted || seq != _quickFillSeq || !_isBuy) return;
      if (context.read<MarketProvider>().selectedPair != pairAtStart) return;
      final out = (q.netOutput * 1e4).floorToDouble() / 1e4;
      if (out > 0) _amountController.text = out.toStringAsFixed(4);
    } on SwapException catch (e) {
      if (!mounted || seq != _quickFillSeq) return;
      _showSnack(e.message(context.read<LocaleProvider>().isThai));
    } catch (_) {
      // เงียบไว้ — ผู้ใช้กรอกเองได้
    }
  }

  /// แถบแจ้งเหตุที่ส่งไม้นี้ไม่ได้ (ใหญ่เกินพูล / เชนติดต่อไม่ได้) — โชว์ก่อนกดส่ง
  Widget _buildDexNotice(String message) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.tradingRedBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.tradingRed, width: 0.8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded,
              size: 14, color: AppColors.tradingRed),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              message,
              style: AppFonts.ui(
                fontSize: 11,
                height: 1.4,
                color: AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// แถบ "ไม้นี้เกินเพดาน" พร้อมปุ่มเติมจำนวนสูงสุดที่ส่งได้
  Widget _buildImpactBlocked(TpixDexQuote q, bool th, String baseSym) {
    final max = _maxBaseUnderLimit(q);
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
              _impactBlockedMessage(q, th, baseSym),
              style: AppFonts.ui(
                fontSize: 10.5,
                height: 1.4,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          if (max != null && max > 0)
            TextButton(
              onPressed: _isSubmitting
                  ? null
                  : () {
                      // ปัดลง — ตัวเลขที่เติมต้องไม่เกินเพดานเอง
                      _amountController.text =
                          TpixDexService.formatMaxAmount(max);
                    },
              style: TextButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                minimumSize: const Size(0, 30),
                foregroundColor: AppColors.gold1,
              ),
              child: Text(
                th ? 'ใช้สูงสุด' : 'Use max',
                style: AppFonts.ui(
                    fontSize: 11, fontWeight: FontWeight.w700),
              ),
            ),
        ],
      ),
    );
  }

  /// สีของ price impact — เหมือนกันทั้งพรีวิวและข้อความ: <1% ปกติ · ≥3% เตือน · >10% ส่งไม่ได้
  static const Color _impactWarn = Color(0xFFF59E0B);

  Color _impactColor(double impact) {
    if (impact > kTpixMaxPriceImpactPct) return AppColors.tradingRed;
    if (impact >= 3) return _impactWarn;
    if (impact >= 1) return AppColors.textPrimary;
    return AppColors.textSecondary;
  }

  /// ข้อความ price impact — null/ไม่ใช่ตัวเลข = "—" (วัดไม่ได้ ไม่ใช่ 0%)
  String _impactText(double? impact) {
    if (impact == null || !impact.isFinite) return '—';
    return impact < 0.01 ? '< 0.01%' : '${impact.toStringAsFixed(2)}%';
  }

  /// จำนวนสูงสุดที่ไม่เกินเพดาน price impact ในหน่วยของช่อง "จำนวน" (base เสมอ)
  /// ซื้อ = TPIX ที่ได้ออก (exact-out) · ขาย = TPIX ที่จ่ายเข้า (exact-in)
  double? _maxBaseUnderLimit(TpixDexQuote q) =>
      _isBuy ? q.maxAmountOutUnderLimit : q.maxAmountInUnderLimit;

  String _impactBlockedMessage(TpixDexQuote q, bool th, String baseSym) {
    // วัดไม่ได้ = ไม่รู้ว่าพูลรับไม้นี้ได้ไหม — คนละข้อความกับ "เกินเพดาน"
    if (q.impactUnknown) return kTpixImpactUnknown.message(th);
    final impact = q.priceImpact!.toStringAsFixed(1);
    final limit = kTpixMaxPriceImpactPct.toStringAsFixed(0);
    final max = _maxBaseUnderLimit(q);
    if (max != null && max > 0) {
      final s = TpixDexService.formatMaxAmount(max);
      return th
          ? 'ไม้นี้ดันราคาพูลไป $impact% เกินเพดาน $limit% — สั่งได้สูงสุดราว $s $baseSym ต่อไม้'
          : 'This order moves the pool price by $impact% (limit $limit%). Max ≈ $s $baseSym per order.';
    }
    return th
        ? 'ไม้นี้ดันราคาพูลไป $impact% เกินเพดาน $limit% — ลองจำนวนน้อยลง'
        : 'This order moves the pool price by $impact% (limit $limit%). Try a smaller amount.';
  }

  String _fmtAmount(double v) {
    if (v >= 1000) return v.toStringAsFixed(2);
    if (v >= 1) return v.toStringAsFixed(4);
    return v.toStringAsFixed(6);
  }

  /// ป้ายแท็บพร้อมตราเล็ก ๆ (เช่น Limit · เว็บ) — บอกว่าแท็บนี้ทำที่ไหน
  Widget _badgeTabLabel(String label, String badge) {
    return FittedBox(
      fit: BoxFit.scaleDown,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label),
          const SizedBox(width: 4),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
            decoration: BoxDecoration(
              color: const Color(0xFFF59E0B).withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(3),
            ),
            child: Text(
              badge,
              style: AppFonts.ui(
                fontSize: 8,
                fontWeight: FontWeight.w700,
                color: const Color(0xFFF59E0B),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// คู่ที่ยังไม่เปิดเทรด (TPIX รอเชน) — แผง Coming Soon แทนฟอร์ม
  /// [paused] = คู่ในทะเบียน BSC ที่เซิร์ฟเวอร์ปิดอยู่ / ไม่ได้เปิดบน BSC (ไม่ใช่ "รอเชน TPIX")
  Widget _buildComingSoonCard(LocaleProvider locale, MarketProvider market,
      {bool checking = false, bool paused = false}) {
    final pair = market.selectedTicker?.displaySymbol ?? market.selectedPair;
    if (paused) return _buildPausedCard(locale, pair);
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: AppTheme.radiusXl,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 28),
      child: Column(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: AppColors.goldTint,
              shape: BoxShape.circle,
              border: Border.all(color: AppColors.goldBorder, width: 1),
            ),
            child: checking
                ? Padding(
                    padding: const EdgeInsets.all(12),
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: AppColors.gold2),
                  )
                : Icon(Icons.lock_clock_rounded,
                    size: 24, color: AppColors.gold2),
          ),
          const SizedBox(height: 12),
          Text(
            checking
                ? (locale.isThai
                    ? 'กำลังตรวจสอบกระดาน...'
                    : 'Checking market...')
                : '$pair — Coming Soon',
            style: AppFonts.ui(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            checking
                ? (locale.isThai
                    ? 'กำลังอ่านสถานะสัญญาบนเชน TPIX'
                    : 'Reading contract status on TPIX Chain')
                : (locale.isThai
                    ? 'คู่นี้ยังไม่มีสภาพคล่องให้เทรด\nระหว่างนี้เทรดคู่เหรียญหลักได้จริงบน BSC'
                    : 'This pair has no liquidity to trade yet.\nMeanwhile, trade major pairs live on BSC.'),
            textAlign: TextAlign.center,
            style: AppFonts.ui(
              fontSize: 11.5,
              height: 1.5,
              color: AppColors.textTertiary,
            ),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: const Color(0xFFF59E0B).withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              checking ? 'TPIX Chain' : 'TPIX Chain — Coming Soon',
              style: AppFonts.ui(
                fontSize: 10,
                fontWeight: FontWeight.w700,
                color: const Color(0xFFF59E0B),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// คู่ BSC ที่ปิดการเทรดอยู่ (แอดมินปิด / ไม่ได้เปิดแบบ onchain บน BSC)
  Widget _buildPausedCard(LocaleProvider locale, String pair) {
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: AppTheme.radiusXl,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 28),
      child: Column(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: AppColors.goldTint,
              shape: BoxShape.circle,
              border: Border.all(color: AppColors.goldBorder, width: 1),
            ),
            child: Icon(Icons.pause_circle_outline_rounded,
                size: 24, color: AppColors.gold2),
          ),
          const SizedBox(height: 12),
          Text(
            locale.isThai ? '$pair — ปิดการเทรดอยู่' : '$pair — Trading paused',
            style: AppFonts.ui(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            locale.isThai
                ? 'คู่นี้ยังไม่เปิดให้เทรดในขณะนี้\nยังดูราคาและกราฟได้ตามปกติ'
                : "This pair isn't open for trading right now.\nYou can still follow its price and chart.",
            textAlign: TextAlign.center,
            style: AppFonts.ui(
              fontSize: 11.5,
              height: 1.5,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  /// Preview ตัวเลขจริงจาก router — You receive / Min received / Fee
  Widget _buildMarketPreview(LocaleProvider locale, MarketProvider market) {
    // ไม้ลงได้สองเชน — หยิบตัวเลขจาก quote ที่ตรงกับเชนของคู่นี้
    final dq = _dexQuote;
    final bq = _marketQuote;
    final netOutput = dq?.netOutput ?? bq?.netOutput;
    final minReceived = dq?.minReceived ?? bq?.minReceived;
    final feeAmount = dq?.feeAmount ?? bq?.feeAmount;
    final feeRate = dq?.feeRate ?? bq?.feeRate;
    final hasQuote = dq != null || bq != null;
    final toSym = _isBuy ? _baseOf(market) : _quoteOf(market);
    final fromSym = _isBuy ? _quoteOf(market) : _baseOf(market);

    Widget row(String label, String? value, {Color? valueColor}) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            Text(label,
                style: AppFonts.ui(
                    fontSize: 11, color: AppColors.textTertiary)),
            const Spacer(),
            if (value == null)
              const SizedBox(
                width: 60,
                height: 10,
                child: LinearProgressIndicator(minHeight: 2),
              )
            else
              Text(value,
                  style: AppTheme.mono(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: valueColor ?? AppColors.textSecondary,
                  )),
          ],
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.goldBorder, width: 0.8),
      ),
      child: Column(
        children: [
          row(
            locale.isThai ? 'จะได้รับ ≈' : 'You receive ≈',
            hasQuote ? '${_fmtAmount(netOutput!)} $toSym' : null,
            valueColor: AppColors.textPrimary,
          ),
          row(
            locale.isThai ? 'ขั้นต่ำที่ได้รับ' : 'Min received',
            hasQuote ? '${_fmtAmount(minReceived!)} $toSym' : null,
          ),
          row(
            locale.isThai
                ? 'ค่าธรรมเนียม (${feeRate?.toStringAsFixed(2) ?? '—'}%)'
                : 'Fee (${feeRate?.toStringAsFixed(2) ?? '—'}%)',
            hasQuote ? '${_fmtAmount(feeAmount!)} $fromSym' : null,
          ),
          // ไม้ใหญ่เทียบกับพูลจะดันราคาเอง — บอกทุกไม้ก่อนกด ไม่ใช่ให้ไปเห็นตอนได้ของน้อย
          // (พูลบนเชน TPIX ยังเล็ก ไม้ไม่กี่ดอลลาร์ก็ขยับราคาเป็นเปอร์เซ็นต์ได้)
          if (dq != null)
            row(
              locale.isThai ? 'ผลต่อราคาพูล' : 'Price impact',
              _impactText(dq.priceImpact),
              // วัดไม่ได้ = ส่งไม่ได้ — สีเดียวกับเกินเพดาน
              valueColor: dq.impactUnknown
                  ? AppColors.tradingRed
                  : _impactColor(dq.priceImpact!),
            ),
          if (dq != null && dq.exceedsImpactLimit) ...[
            const SizedBox(height: 6),
            _buildImpactBlocked(dq, locale.isThai, _baseOf(market)),
          ],
          // BSC: วัดจากไม้จิ๋วบนเส้นทางเดียวกัน — "—" = วัดไม่ได้ (หน้าเทรดยังมีด่านเทียบราคาตลาดอีกชั้น)
          if (dq == null && bq != null)
            row(
              locale.isThai ? 'ผลต่อราคาพูล' : 'Price impact',
              _impactText(bq.priceImpact),
              valueColor: bq.priceImpact == null
                  ? AppColors.textSecondary
                  : _impactColor(bq.priceImpact!),
            ),
          if (dq == null &&
              bq != null &&
              bq.priceImpact != null &&
              bq.priceImpact! > kBscMaxPriceImpactPct) ...[
            const SizedBox(height: 6),
            _buildDexNotice(
                swapImpactTooHigh(bq.priceImpact!).message(locale.isThai)),
          ],
          const SizedBox(height: 4),
          Row(
            children: [
              Icon(Icons.verified_rounded, size: 10, color: AppColors.gold2),
              const SizedBox(width: 4),
              Text(
                dq != null
                    ? (locale.isThai
                        ? 'ราคาจริงจากพูล TPIX DEX (เชน TPIX) — ค่าธรรมเนียมรวมในราคาแล้ว'
                        : 'Live pool price on TPIX Chain — fee already in the price')
                    : (locale.isThai
                        ? 'ราคาจริงจาก PancakeSwap (BSC)'
                        : 'Live price from PancakeSwap (BSC)'),
                style: AppFonts.ui(
                    fontSize: 9, color: AppColors.textTertiary),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ── Fee Summary ──

  Widget _buildFeeSummary(LocaleProvider locale, MarketProvider market) {
    final config = context.watch<ConfigProvider>();
    if (!config.isReady) return const SizedBox.shrink();

    final feeRate = config.feeRateForPair(market.selectedPair);
    final pair = config.pairBySymbol(market.selectedPair);

    // คำนวณ estimated fee จาก amount ที่ user กรอก
    final amount = double.tryParse(_amountController.text) ?? 0;
    final total = amount * (market.selectedTicker?.lastPrice ?? 0);
    final feeAmount = total * (feeRate / 100);
    final quoteAsset = market.selectedTicker?.quoteAsset ?? 'USDT';

    // แสดง warning ถ้าเกิน min/max
    String? warning;
    if (pair != null && amount > 0) {
      if (pair.minTradeAmount > 0 && amount < pair.minTradeAmount) {
        warning = locale.isThai
            ? 'ต่ำกว่าขั้นต่ำ ${pair.minTradeAmount}'
            : 'Below min ${pair.minTradeAmount}';
      } else if (pair.maxTradeAmount > 0 && amount > pair.maxTradeAmount) {
        warning = locale.isThai
            ? 'เกินสูงสุด ${pair.maxTradeAmount}'
            : 'Above max ${pair.maxTradeAmount}';
      }
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: warning != null
              ? AppColors.tradingRed
              : AppColors.bgCardBorder,
        ),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Icon(Icons.receipt_long_rounded,
                  size: 13, color: AppColors.textTertiary),
              const SizedBox(width: 6),
              Text(
                locale.isThai ? 'ค่าธรรมเนียม' : 'Fee',
                style: AppFonts.ui(
                  fontSize: 11,
                  color: AppColors.textTertiary,
                ),
              ),
              const Spacer(),
              Text(
                '${feeRate.toStringAsFixed(2)}%',
                style: AppTheme.mono(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.gold2,
                ),
              ),
              if (feeAmount > 0 && amount > 0) ...[
                const SizedBox(width: 6),
                Text(
                  '(~${feeAmount.toStringAsFixed(2)} $quoteAsset)',
                  style: AppTheme.mono(
                      fontSize: 10, color: AppColors.textTertiary),
                ),
              ],
            ],
          ),
          if (warning != null) ...[
            const SizedBox(height: 6),
            Row(
              children: [
                Icon(Icons.warning_amber_rounded,
                    size: 12, color: AppColors.tradingRed),
                const SizedBox(width: 6),
                Text(
                  warning,
                  style: AppFonts.ui(
                    fontSize: 10,
                    color: AppColors.tradingRed,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ── Open Orders ──

  /// คำสั่งที่เปิดอยู่ของกระเป๋านี้ — ทุกคู่ (คู่ที่ดูอยู่ขึ้นก่อน)
  ///
  /// รวมคำสั่งตั้งราคาของคู่บนเชนที่วางจากหน้าเว็บ (`source: limit`): เซิร์ฟเวอร์เฝ้าราคา
  /// แล้วให้เจ้าของยืนยันในกระเป๋าเมื่อราคาถึง — แอปนี้แสดงและยกเลิกได้ (เฉพาะที่ยังรอราคา)
  /// ยังวาง/ส่งคำสั่งตั้งราคาจากแอปไม่ได้
  Widget _buildOpenOrders(WalletProvider wallet, LocaleProvider locale) {
    final current =
        TradeOrder.normalizePair(context.read<MarketProvider>().selectedPair);
    final orders = [
      ...wallet.openOrders.where((o) => o.pairKey == current),
      ...wallet.openOrders.where((o) => o.pairKey != current),
    ];

    if (orders.isEmpty) return const SizedBox.shrink();
    final hasLimitWatch = orders.any((o) => o.isLimitWatch);

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: AppTheme.radiusLg,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.pending_actions_rounded,
                  size: 15, color: AppColors.gold2),
              const SizedBox(width: 7),
              Text(
                locale.t('trade.open_orders'),
                style: AppFonts.ui(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          ...orders.map((order) => _OpenOrderRow(
                order: order,
                locale: locale,
                busy: _cancelling.contains(order.id),
                onCancel: order.isCancellable
                    ? () => _cancelOrder(order, wallet)
                    : null,
              )),
          if (hasLimitWatch) ...[
            const SizedBox(height: 2),
            Text(
              locale.isThai
                  ? 'คำสั่งตั้งราคา: เมื่อราคาถึง ต้องยืนยันในกระเป๋าที่หน้าเว็บ tpix.online — แอปนี้ยังส่งคำสั่งตั้งราคาไม่ได้'
                  : 'Limit orders: when the price is reached, confirm in your wallet on the web (tpix.online) — this app cannot send limit orders yet.',
              style: AppFonts.ui(
                fontSize: 10,
                height: 1.4,
                color: AppColors.textTertiary,
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// ยกเลิกคำสั่ง — ถามยืนยันก่อน (ยกเลิกแล้วต้องไปวางใหม่ที่หน้าเว็บ) กันกดซ้ำ และบอกผลทุกทาง
  Future<void> _cancelOrder(TradeOrder order, WalletProvider wallet) async {
    final address = wallet.address;
    if (address == null || order.id.isEmpty) return;
    if (_cancelling.contains(order.id)) return;
    final locale = context.read<LocaleProvider>();
    final th = locale.isThai;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: AppColors.bgCardBorder),
        ),
        title: Text(
          th ? 'ยกเลิกคำสั่งนี้?' : 'Cancel this order?',
          style: AppFonts.ui(
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary),
        ),
        content: Text(
          th
              ? '${_orderSummary(order, th)}\nยกเลิกแล้วย้อนกลับไม่ได้ — ถ้าต้องการอีกต้องวางคำสั่งใหม่'
              : '${_orderSummary(order, th)}\nThis cannot be undone — you would have to place the order again.',
          style: AppFonts.ui(fontSize: 13, color: AppColors.textSecondary),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(th ? 'ไม่ยกเลิก' : 'Keep order',
                style: TextStyle(color: AppColors.textTertiary)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(th ? 'ยกเลิกคำสั่ง' : 'Cancel order',
                style: TextStyle(color: AppColors.tradingRed)),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    // กระเป๋าถูกสลับระหว่างที่หน้ายืนยันเปิดอยู่ — คำสั่งนี้ไม่ใช่ของกระเป๋าที่เชื่อมอยู่แล้ว
    if (wallet.address?.toLowerCase() != address.toLowerCase()) return;
    if (_cancelling.contains(order.id)) return;

    setState(() => _cancelling.add(order.id));
    var ok = false;
    try {
      ok = await ApiService().cancelOrder(order.id, address);
    } catch (_) {
      ok = false;
    }
    if (!mounted) return;
    setState(() => _cancelling.remove(order.id));
    // สำเร็จหรือไม่ก็โหลดรายการใหม่ — ยกเลิกไม่ได้ส่วนใหญ่เพราะสถานะเปลี่ยนไปแล้ว (ราคาถึง/ส่งธุรกรรมแล้ว)
    wallet.loadPortfolio();
    _showSnack(
      ok
          ? (th ? 'ยกเลิกคำสั่งแล้ว' : 'Order cancelled')
          : (th
              ? 'ยกเลิกคำสั่งไม่สำเร็จ — คำสั่งอาจถูกส่งหรือปิดไปแล้ว ลองใหม่อีกครั้ง'
              : 'Could not cancel the order — it may already be submitted or closed. Please try again.'),
      isSuccess: ok,
    );
  }

  /// สรุปคำสั่งหนึ่งบรรทัด (สำหรับหน้ายืนยัน)
  String _orderSummary(TradeOrder order, bool th) {
    final side = switch (order.tradeSide) {
      TradeSide.buy => th ? 'ซื้อ' : 'Buy',
      TradeSide.sell => th ? 'ขาย' : 'Sell',
      TradeSide.neutral => th ? 'คำสั่ง' : 'Order',
    };
    final price = order.price != null && order.price! > 0
        ? ' @ ${_fmtAmount(order.price!)}'
        : '';
    return '$side ${_fmtAmount(order.amount)} ${order.pairKey.replaceAll('-', '/')}$price';
  }

  // ── Pair picker ──

  void _showPairPicker(MarketProvider market) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true, // กัน Android nav bar บัง content ด้านล่าง
      backgroundColor: Colors.transparent,
      builder: (_) => _PairPickerSheet(
        market: market,
        onSelect: (pair) {
          market.selectPair(pair);
          Navigator.pop(context);
        },
      ),
    );
  }

  // ── Submit order ──
  // Dispatcher — แยกเส้นทางเหมือนเว็บ:
  //  คู่ major (ทะเบียน BSC + เซิร์ฟเวอร์ยืนยัน) → market order execute จริงผ่าน PancakeSwap
  //  คู่บนเชน TPIX ที่มีพูล                     → market order ผ่าน TPIX DEX
  //  อื่น ๆ                                      → ยังไม่เปิด (ฟอร์มถูกซ่อนอยู่แล้ว)
  // แอปส่งได้เฉพาะ Market — คำสั่งตั้งราคาวางที่หน้าเว็บ (แท็บ Limit อธิบายไว้)

  Future<void> _submitOrder() async {
    if (_isSubmitting) return;

    final wallet = context.read<WalletProvider>();
    final market = context.read<MarketProvider>();
    final locale = context.read<LocaleProvider>();

    if (!wallet.isConnected || wallet.address == null) return;

    if (!_isMarket) {
      _showSnack(locale.isThai
          ? 'คำสั่งตั้งราคาวางได้ที่หน้าเว็บ tpix.online — ในแอปใช้ Market'
          : 'Limit orders are placed on the web (tpix.online) — use Market in the app');
      return;
    }

    final gate = _bscGate(market);
    if (gate == BscPairGate.tradable) {
      await _executeBscMarketOrder(wallet, market, locale);
      return;
    }
    // กันอีกชั้น (ปุ่มถูกปิด/ฟอร์มถูกซ่อนอยู่แล้ว): ยังไม่ยืนยันว่าคู่นี้เปิดบน BSC = ไม่ส่ง
    final gateReason = _bscGateReason(gate, locale.isThai);
    if (gateReason != null) {
      _showSnack(gateReason);
      return;
    }

    final dexPair = _dexPairFor(market);
    if (dexPair != null) {
      await _executeDexMarketOrder(wallet, market, locale, dexPair);
      return;
    }

    // กันไว้อีกชั้น — ฟอร์มถูกแทนด้วย Coming Soon card อยู่แล้ว
    _showSnack(_isChainPairNow(market)
        ? (locale.isThai
            ? 'คู่นี้ยังไม่มีพูลบนเชน TPIX — เติมสภาพคล่องที่หน้าเว็บก่อน'
            : 'This pair has no pool on TPIX Chain yet')
        : (locale.isThai
            ? 'คู่นี้ยังไม่เปิดให้เทรดในขณะนี้'
            : "This pair isn't open for trading right now."));
  }

  /// ด่านก่อนเงินออก: กระเป๋าต้องยืนยันกับเซิร์ฟเวอร์แล้ว + ผ่านด่านยืนยันตัวตนของการเทรด
  /// (ไม่ผ่านโยน [TradeBlockedException] — ยังไม่ได้ส่งอะไร) แล้วเช็กว่ากระเป๋ายังเป็นใบเดิม
  Future<void> _preflight(WalletProvider wallet, String address) async {
    await TradePreflight.ensureCanTrade(
      address: address,
      isVerified: () => wallet.isVerified,
      verify: wallet.verifyWithBackend,
    );
    _ensureSameWallet(wallet, address);
  }

  /// แจ้งเหตุที่ส่งไม้ไม่ได้ — ติดด่านยืนยันตัวตน = พาไปหน้ายืนยันตัวตนในแอปเลย
  void _showTradeBlocked(TradeBlockedException e, LocaleProvider locale) {
    if (!mounted) return;
    if (e.needsKyc) {
      _showSnack(
        e.message(locale.isThai),
        actionLabel: locale.isThai ? 'ยืนยันตัวตน' : 'Verify',
        onAction: () => KycNav.open(context),
      );
      KycNav.open(context);
      return;
    }
    _showSnack(e.message(locale.isThai));
  }

  /// Market order เทรดจริงบน BSC ผ่าน PancakeSwap — ลำดับเหมือนเว็บทุกขั้น:
  /// ยืนยันกระเป๋า + ด่านยืนยันตัวตน → quote จริง (ตรวจ token on-chain, อัตราจาก backend แบบ fail-closed)
  /// → กันราคาเพี้ยน/ดันราคาพูล (>10% หรือวัดไม่ได้ = ไม่ส่ง) → ไม่แย่กว่าที่เห็นในพรีวิว
  /// → กระเป๋าค่าธรรมเนียมพร้อม → ตัดยอดให้พอดียอดบนเชน → approve ถ้าจำเป็น → swap → เก็บ fee
  /// → บันทึก backend (คิวส่งซ้ำ) → refresh ยอด
  Future<void> _executeBscMarketOrder(
    WalletProvider wallet,
    MarketProvider market,
    LocaleProvider locale,
  ) async {
    final amountText = _amountController.text.trim();
    final amount = double.tryParse(amountText);
    if (amount == null || amount <= 0) {
      _showSnack(locale.t('trade.invalid_amount'));
      return;
    }

    final marketPrice = market.selectedTicker?.lastPrice ?? 0;
    if (marketPrice <= 0) {
      _showSnack(locale.isThai
          ? 'ราคาตลาดยังไม่พร้อม ลองใหม่อีกครั้ง'
          : 'Market price unavailable — try again.');
      return;
    }

    // จับค่าตั้งต้นไว้ — ผู้ใช้อาจสลับฝั่ง/คู่/กระเป๋าระหว่างที่ไม้นี้ยังวิ่งอยู่
    final isBuy = _isBuy;
    final address = wallet.address!;
    final pairSymbol = market.selectedPair;
    final base = _baseOf(market);
    final quote = _quoteOf(market);
    final fromSym = isBuy ? quote : base;
    final toSym = isBuy ? base : quote;
    final slippage = wallet.slippage;
    final orderKey = _orderKey(market, wallet);
    // buy: จ่าย quote (USDT) = ยอดจากปุ่ม % (ถ้าจำนวนยังเป็นค่าที่ปุ่มเติมให้) ไม่งั้น จำนวน × ราคาตลาด
    // sell: จ่าย base ตรงๆ
    final input = _bscInput(market, wallet);
    if (input == null || !(input.amount > 0)) {
      _showSnack(locale.t('trade.invalid_amount'));
      return;
    }
    final inputAmount = input.amount;
    // ราคาที่ผู้ใช้เห็นในพรีวิว (ถ้าเป็นของไม้นี้) — อ่านก่อนขอราคาใหม่
    final seen = _previewKey == orderKey ? _marketQuote : null;

    setState(() => _isSubmitting = true);

    try {
      // 0) ยืนยันกระเป๋า + ด่านยืนยันตัวตน — ต้องผ่านก่อนเงินออก (เซิร์ฟเวอร์ตรวจตอนบันทึกไม้ ซึ่งสายไปแล้ว)
      await _preflight(wallet, address);

      // 1) Quote จริงจาก router (ตรวจ token กับ on-chain ในตัว · อัตราค่าธรรมเนียมจาก backend — fail-closed)
      //    sell: จำนวน base แปลงจากข้อความตรงตัว ไม่ผ่าน double (ไม่ปัดเกินยอดจริง)
      var swapQuote = await _swapService.getQuote(
        fromSymbol: fromSym,
        toSymbol: toSym,
        amount: inputAmount,
        slippageOverride: slippage,
        amountText: isBuy ? null : amountText,
      );

      // 2) กันราคา on-chain เพี้ยนจากราคาตลาดเกิน 10% (สภาพคล่องบาง/pool ผิดปกติ)
      if (swapQuote.netOutput > 0) {
        final effPrice = isBuy
            ? swapQuote.amountIn / swapQuote.netOutput // จ่าย USDT ต่อ 1 base
            : swapQuote.netOutput / swapQuote.amountIn; // ได้ USDT ต่อ 1 base
        final deviation = (effPrice - marketPrice).abs() / marketPrice;
        if (deviation > 0.10) {
          throw const SwapException(
            'On-chain price differs too much from market price. Try a smaller amount.',
            'ราคาบนเชนต่างจากราคาตลาดมากเกินไป ลองจำนวนน้อยลง',
          );
        }
      }
      // ไม้นี้ดันราคาพูลเกินเพดาน = ไม่ส่ง · วัดไม่ได้ ยอมได้เฉพาะเมื่อมีราคาตลาดเทียบ (ด่านด้านบนผ่านแล้ว)
      final impact = swapQuote.priceImpact;
      if (impact != null && impact > kBscMaxPriceImpactPct) {
        if (mounted) {
          setState(() {
            _marketQuote = swapQuote;
            _bscQuoteError = null;
            _previewKey = null;
          });
        }
        throw swapImpactTooHigh(impact);
      }
      if (swapQuote.isImpactBlocked(hasMarketReference: marketPrice > 0)) {
        throw kSwapImpactUnknown;
      }

      // 3) ราคาใหม่ต้องไม่แย่กว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นในฟอร์ม
      //    พูลขยับระหว่างพรีวิวกับกดส่ง = หยุด โชว์ตัวเลขใหม่ ให้กดยืนยันอีกครั้ง
      if (seen != null && swapQuote.netOutput < seen.minReceived) {
        final fresh = swapQuote;
        if (mounted && _orderKey(market, wallet) == orderKey) {
          setState(() {
            _marketQuote = fresh;
            _bscQuoteError = null;
            _previewKey = orderKey;
          });
        }
        throw SwapException(
          'The pool price moved: you would now receive about ${_fmtAmount(fresh.netOutput)} $toSym (at least ${_fmtAmount(fresh.minReceived)}). Press again to confirm.',
          'ราคาพูลขยับแล้ว: ตอนนี้จะได้ประมาณ ${_fmtAmount(fresh.netOutput)} $toSym (อย่างน้อย ${_fmtAmount(fresh.minReceived)}) กดอีกครั้งเพื่อยืนยัน',
        );
      }

      // 4) กระเป๋าค่าธรรมเนียมต้องพร้อม "ก่อน" ขอให้ผู้ใช้ approve
      //    (เดิมเช็กตอนจะสวอป — จ่ายค่าแก๊ส approve ไปแล้วค่อยเจอ "ระบบยังไม่พร้อม")
      if (swapQuote.feeWei > BigInt.zero) {
        await _swapService.ensureFeeCollector();
      }

      // 5) ยอดที่จะจ่ายต้องไม่เกินยอดจริงบนเชน — เกินด้วยเศษ = ตัดให้พอดี · เกินจริง = ไม่ส่ง
      swapQuote = await _swapService.fitToBalance(swapQuote, address);
      _ensureSameWallet(wallet, address);

      // 6) Approve router ถ้า allowance ไม่พอ (เฉพาะ token ไม่ใช่ BNB)
      if (!swapQuote.fromToken.native) {
        final hasAllowance = await _swapService.hasAllowance(
          swapQuote.fromToken,
          address,
          swapQuote.amountInSwapWei,
        );
        _ensureSameWallet(wallet, address);
        if (!hasAllowance) {
          if (mounted) {
            _showSnack(locale.isThai
                ? 'กำลังขอ approve $fromSym — ยืนยันในกระเป๋า'
                : 'Approving $fromSym — confirm in your wallet');
          }
          final approveHash = await wallet.sendBscTransaction(
            to: swapQuote.fromToken.address,
            data: _swapService.approveCalldata(swapQuote.amountInSwapWei),
            summary: 'Approve $fromSym for PancakeSwap router',
          );
          if (approveHash == null) {
            throw const SwapException(
                'Approval rejected.', 'การ approve ถูกปฏิเสธ');
          }
          final approved = await _swapService.waitConfirmed(approveHash);
          if (!approved) {
            throw const SwapException(
                'Approval not confirmed — try again.',
                'การ approve ยังไม่ยืนยัน ลองใหม่อีกครั้ง');
          }
        }
      }

      // 7) ส่ง swap จริง — service เก็บ fee + เข้าคิวบันทึก backend ให้ครบ
      // ห่อ sender เพื่อแนบ summary ให้ TPIX Wallet โชว์ตอนยืนยัน (linked)
      final actionText =
          '${isBuy ? 'Buy' : 'Sell'} $base — TPIX Trade market order';
      final result = await _swapService.executeMarketSwap(
        quote: swapQuote,
        walletAddress: address,
        meta: SwapRecordMeta(
          pair: pairSymbol,
          side: isBuy ? 'buy' : 'sell',
          price: marketPrice,
        ),
        // minOut บนเชนไม่ต่ำกว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นในพรีวิว
        minOutFloor: seen?.minOutWei,
        // วัด price impact ไม่ได้ ยอมได้เพราะด่านเทียบราคาตลาด (ข้อ 2) ผ่านแล้ว
        allowUnknownImpact: marketPrice > 0,
        sendTx: ({required String to, Uint8List? data, BigInt? value}) {
          // ด่านสุดท้ายก่อนเปิดกระเป๋า — กระเป๋าต้องยังเป็นใบเดียวกับตอนกดซื้อ
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

      // ล้างฟอร์มเฉพาะเมื่อยังเป็นไม้เดิม (ผู้ใช้อาจพิมพ์ไม้ถัดไประหว่างรอ)
      if (_orderKey(market, wallet) == orderKey) _amountController.clear();
      setState(() {
        _marketQuote = null;
        _previewKey = null;
        _quickSpend = null;
        _quickSpendKey = null;
      });
      final received = '${_fmtAmount(result.quote.netOutput)} $toSym';
      _showSnack(
        result.confirmed
            ? (locale.isThai
                ? '${isBuy ? 'ซื้อ' : 'ขาย'}สำเร็จ ≈ $received บน BSC'
                : '${isBuy ? 'Bought' : 'Sold'} ≈ $received on BSC')
            : (locale.isThai
                ? 'ส่งธุรกรรมแล้ว กำลังรอยืนยันบนเชน'
                : 'Transaction sent — awaiting confirmation'),
        isSuccess: result.confirmed,
        actionLabel: locale.isThai ? 'ดู tx' : 'View tx',
        onAction: () => launchUrl(
          Uri.parse(result.explorerUrl),
          mode: LaunchMode.externalApplication,
        ),
      );

      // Refresh ยอดจริงจาก BSC + portfolio
      _bscBalanceKey = null;
      _loadBscBalances();
      wallet.loadPortfolio();
    } on TradeBlockedException catch (e) {
      _showTradeBlocked(e, locale);
    } on SwapException catch (e) {
      if (mounted) _showSnack(e.message(locale.isThai));
    } catch (_) {
      if (mounted) {
        _showSnack(wallet.error ?? locale.t('common.error'));
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  /// Market order เทรดจริงบนเชน TPIX ผ่านพูลของ TPIX DEX
  ///
  /// ต่างจากฝั่ง BSC สองเรื่อง:
  ///   1. ค่าธรรมเนียม 0.3% อยู่ในพูลแล้ว ไม่มีธุรกรรมโอนค่าธรรมเนียมใบที่สอง
  ///   2. ไม่เทียบกับราคา Binance เพราะพูลนี้เองคือตลาดของคู่นี้
  ///      — ใช้ price impact แทน (ไม้ใหญ่เกินพูลจะดันราคาเอง)
  Future<void> _executeDexMarketOrder(
    WalletProvider wallet,
    MarketProvider market,
    LocaleProvider locale,
    TradingPairInfo pair,
  ) async {
    final amountText = _amountController.text;
    final amount = double.tryParse(amountText.trim());
    if (amount == null || amount <= 0) {
      _showSnack(locale.t('trade.invalid_amount'));
      return;
    }

    final base = _baseOf(market);
    final quoteSym = _quoteOf(market);
    // จับค่าตั้งต้นไว้ — ผู้ใช้อาจสลับฝั่ง/คู่/กระเป๋าระหว่างที่ไม้นี้ยังวิ่งอยู่
    final isBuy = _isBuy;
    final address = wallet.address!;
    final pairSymbol = market.selectedPair;
    final orderKey = _orderKey(market, wallet);
    // ราคาที่ผู้ใช้เห็นในพรีวิว (ถ้าเป็นของไม้นี้) — อ่านก่อนขอราคาใหม่
    final seen = _previewKey == orderKey ? _dexQuote : null;

    setState(() => _isSubmitting = true);

    final chainId = _dexService.lastConfig.chainId;
    String? sendError;

    try {
      // 0) ยืนยันกระเป๋า + ด่านยืนยันตัวตน — ต้องผ่านก่อนเงินออก
      await _preflight(wallet, address);

      final (baseTok, quoteTok) = await _dexTokens(pair, market);
      final fromTok = isBuy ? quoteTok : baseTok;
      final toTok = isBuy ? baseTok : quoteTok;
      // จำนวน (หน่วย base) จากข้อความตรงตัว — ไม่ผ่าน double (ดู TpixDexService.parseUnits)
      final amountWei = TpixDexService.parseUnits(amountText, baseTok.decimals);
      if (amountWei == null || amountWei <= BigInt.zero) {
        throw SwapException(locale.t('trade.invalid_amount'),
            locale.t('trade.invalid_amount'));
      }

      // 1) Quote จริงจาก router ของพูล — ยอดที่จะจ่าย/ได้มาจาก router ทั้งหมด
      //    buy: ใส่จำนวน TPIX ที่อยากได้ → getAmountsIn บอกยอด USDT ที่ต้องจ่าย
      //    (เดิมคูณราคาจาก ticker ซึ่งเป็น 0 ได้นานถึง 30 วิหลังเปิดแอป = ซื้อไม่ได้)
      final swapQuote = isBuy
          ? await _dexService.getQuoteForOutput(
              fromToken: fromTok,
              toToken: toTok,
              amountOut: amount,
              amountOutWei: amountWei,
              slippage: wallet.slippage,
            )
          : await _dexService.getQuote(
              fromToken: fromTok,
              toToken: toTok,
              amount: amount,
              amountInWei: amountWei,
              slippage: wallet.slippage,
            );

      // 2) กันไม้ที่ดันราคาพูลเกิน 10% — เพดานเดียวกับเว็บ บอกจำนวนสูงสุดที่ส่งได้ด้วย
      //    วัดไม่ได้ (อ่านพูลไม่ได้) = ไม่ส่งเช่นกัน — ไม่รู้ว่าพูลรับไม้ขนาดนี้ได้ไหม
      if (swapQuote.exceedsImpactLimit) {
        final en = _impactBlockedMessage(swapQuote, false, base);
        final th = _impactBlockedMessage(swapQuote, true, base);
        if (mounted) {
          setState(() {
            _dexQuote = swapQuote;
            _dexQuoteError = null;
            _previewKey = null;
          });
        }
        throw SwapException(en, th);
      }

      // ราคาใหม่ต้องไม่แย่กว่าที่ผู้ใช้เห็นในฟอร์ม — พูลขยับระหว่างพรีวิวกับกดส่ง = หยุด
      // โชว์ตัวเลขใหม่ ให้กดยืนยันอีกครั้ง
      //   ขาย (exact-in): ได้รับน้อยกว่า "ได้รับอย่างน้อย" ที่เห็น
      //   ซื้อ (exact-out): ต้องจ่ายมากกว่าที่เห็นเกินค่า slippage ที่ตั้งไว้
      if (seen != null) {
        final worse = isBuy
            ? swapQuote.amountIn > seen.amountIn * (1 + seen.slippage / 100)
            : swapQuote.netOutput < seen.minReceived;
        if (worse) {
          if (mounted && _orderKey(market, wallet) == orderKey) {
            setState(() {
              _dexQuote = swapQuote;
              _dexQuoteError = null;
              _previewKey = orderKey;
            });
          }
          throw isBuy
              ? SwapException(
                  'The pool price moved: this order now costs about ${_fmtAmount(swapQuote.amountIn)} ${fromTok.symbol}. Press again to confirm.',
                  'ราคาพูลขยับแล้ว: ตอนนี้ต้องจ่ายประมาณ ${_fmtAmount(swapQuote.amountIn)} ${fromTok.symbol} กดอีกครั้งเพื่อยืนยัน',
                )
              : SwapException(
                  'The pool price moved: you would now receive about ${_fmtAmount(swapQuote.netOutput)} ${toTok.symbol} (at least ${_fmtAmount(swapQuote.minReceived)}). Press again to confirm.',
                  'ราคาพูลขยับแล้ว: ตอนนี้จะได้ประมาณ ${_fmtAmount(swapQuote.netOutput)} ${toTok.symbol} (อย่างน้อย ${_fmtAmount(swapQuote.minReceived)}) กดอีกครั้งเพื่อยืนยัน',
                );
        }
      }

      // ค่าวางไม้ของไม้บน TPIX Chain — จ่ายด้วยเครดิต TPIX ทุกไม้ (เจ้าของสั่ง 2026-11-13)
      // ถามใบเสนอราคาสดก่อนขอ approve: ถามไม่ได้ / เครดิตไม่พอ = ไม่ส่ง (ยังไม่มีอะไรออกจากกระเป๋า)
      // มูลค่าไม้ = ฝั่ง USDT ของไม้ (ที่อยู่ตรงกับ USDT ของทะเบียน DEX — ไม่เชื่อชื่อเหรียญ)
      final usdt = (_dexService.lastConfig.usdt ?? '').toLowerCase();
      bool isUsdt(VerifiedToken t) =>
          !t.native && usdt.isNotEmpty && t.address.toLowerCase() == usdt;
      final feePlan = await TradingFeeFlow.plan(
        wallet: address,
        pair: pairSymbol.replaceAll('-', '/'),
        side: isBuy ? 'buy' : 'sell',
        orderValueUsd: TradingFeeFlow.orderValueUsd(
          fromIsStable: isUsdt(fromTok),
          toIsStable: isUsdt(toTok),
          amountIn: swapQuote.amountIn,
          netOutput: swapQuote.netOutput,
        ),
        chainId: chainId,
      );
      _ensureSameWallet(wallet, address);

      // ยอดที่จะจ่ายต้องไม่เกินที่มีจริงบนเชน (อ่านได้แล้วเท่านั้น — ไม่รู้ยอดก็ปล่อยให้เชนตัดสิน)
      // เทียบเป็นหน่วยเล็กสุด — double ทำให้ "ขาย 100%" ของยอดหลักร้อยล้านเด้งว่าไม่พอ
      final have = isBuy ? _dexQuoteBalance : _dexBaseBalance;
      final haveWei = isBuy ? _dexQuoteBalanceWei : _dexBaseBalanceWei;
      if (_dexBalancesFor == '$pairSymbol|$address' &&
          have != null &&
          haveWei != null &&
          swapQuote.amountInWei > haveWei) {
        throw SwapException(
          'Insufficient ${fromTok.symbol} — need ${_fmtAmount(swapQuote.amountIn)}, have ${_fmtAmount(have)}.',
          '${fromTok.symbol} ไม่พอ — ต้องใช้ ${_fmtAmount(swapQuote.amountIn)} มี ${_fmtAmount(have)}',
        );
      }

      // 3) Approve router ถ้า allowance ไม่พอ (เฉพาะโทเคน ไม่ใช่ TPIX เนทีฟ)
      if (!fromTok.native) {
        final ok = await _dexService.hasAllowance(
            fromTok, address, swapQuote.amountInWei);
        _ensureSameWallet(wallet, address);
        if (!ok) {
          if (mounted) {
            _showSnack(locale.isThai
                ? 'กำลังขอ approve ${fromTok.symbol} — ยืนยันในกระเป๋า'
                : 'Approving ${fromTok.symbol} — confirm in your wallet');
          }
          final approveHash = await wallet.sendChainTransaction(
            chainId: chainId,
            to: fromTok.address,
            // approve เท่ายอดของไม้นี้พอดี ไม่เปิดสิทธิ์ไม่จำกัดค้างไว้
            data: await _dexService.approveCalldata(
                fromTok.address, swapQuote.amountInWei),
            summary: 'Approve ${fromTok.symbol} for TPIX DEX router',
          );
          if (approveHash == null) {
            // เหตุจริงอยู่ใน wallet.error (ผู้ใช้ปฏิเสธ / สลับเชนไม่ได้ / เครือข่ายล่ม)
            final why = wallet.error;
            throw SwapException(
                why ?? 'Approval rejected.', why ?? 'การ approve ถูกปฏิเสธ');
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

      // 4) ส่ง swap จริง — service บันทึกฝั่งเซิร์ฟเวอร์ให้แล้ว
      final actionText =
          '${isBuy ? 'Buy' : 'Sell'} $base — TPIX Trade (TPIX Chain)';
      // ต้องมีใบอนุญาตวางไม้ (หักเครดิต TPIX) = ขอใบ → ส่ง → ผูกกับ tx → ปิดใบเมื่อไม้ยืนยัน
      // ไม่มีอะไรออกจากกระเป๋า = คืนค่าบริการ · ออกไปแล้ว = เซิร์ฟเวอร์ตัดสินจากเชน (TradingFeeFlow)
      final result = await TradingFeeFlow.send<TpixDexSwapResult>(
        plan: feePlan,
        isConfirmed: (r) => r.confirmed,
        send: (onSent) => _dexService.executeMarketSwap(
          quote: swapQuote,
          walletAddress: address,
          onSent: onSent,
          meta: SwapRecordMeta(
            pair: pairSymbol,
            side: isBuy ? 'buy' : 'sell',
            price: market.selectedTicker?.lastPrice,
          ),
          // ขาย: minOut บนเชนไม่ต่ำกว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นในพรีวิว
          minOutFloor: isBuy ? null : seen?.minOutWei,
          sendTx: (
              {required String to, Uint8List? data, BigInt? value}) async {
            // ด่านสุดท้ายก่อนเปิดกระเป๋า — กระเป๋าต้องยังเป็นใบเดียวกับตอนกดซื้อ
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
        ),
      );

      if (!mounted) return;

      // ล้างฟอร์มเฉพาะเมื่อยังเป็นไม้เดิม (ผู้ใช้อาจพิมพ์ไม้ถัดไประหว่างรอ)
      if (_orderKey(market, wallet) == orderKey) _amountController.clear();
      setState(() {
        _dexQuote = null;
        _dexQuoteError = null;
        _previewKey = null;
      });
      final received =
          '${_fmtAmount(swapQuote.netOutput)} ${toTok.symbol}';
      _showSnack(
        result.confirmed
            ? (locale.isThai
                ? '${isBuy ? 'ซื้อ' : 'ขาย'}สำเร็จ ≈ $received บนเชน TPIX'
                : '${isBuy ? 'Bought' : 'Sold'} ≈ $received on TPIX Chain')
            : (locale.isThai
                ? 'ส่งธุรกรรมแล้ว กำลังรอยืนยันบนเชน'
                : 'Transaction sent — awaiting confirmation'),
        isSuccess: result.confirmed,
        actionLabel: locale.isThai ? 'ดู tx' : 'View tx',
        onAction: () => launchUrl(
          Uri.parse(result.explorerUrl),
          mode: LaunchMode.externalApplication,
        ),
      );

      // Refresh ยอดจริงจากเชน + ฟีดของคู่
      _dexBalanceKey = null;
      _loadDexBalances();
      market.loadOrderBook();
      market.loadTpixPrice(retries: 0); // ไม้นี้ขยับราคาพูลแล้ว
      wallet.loadPortfolio();
      // ป้องกันคนอ่านตัวเลขเก่า — quoteSym ใช้เฉพาะข้อความ ไม่ได้ใช้คำนวณ
      debugPrint('TPIX DEX order done: $base/$quoteSym');
    } on TradeBlockedException catch (e) {
      _showTradeBlocked(e, locale);
    } on TradingFeeException catch (e) {
      // ติดที่ค่าวางไม้ (ยังไม่มีอะไรออกจากกระเป๋า) — บอกเหตุ และพาไปเติมเครดิตที่หน้าเว็บถ้าขาด
      if (mounted) {
        _showSnack(
          e.message(locale.isThai),
          actionLabel: e.needsCredit
              ? (locale.isThai ? 'เปิดหน้าเว็บ' : 'Open web')
              : null,
          onAction: e.needsCredit
              ? () => launchUrl(
                    Uri.parse(
                        'https://tpix.online/trade/${Uri.encodeComponent(pairSymbol)}'),
                    mode: LaunchMode.externalApplication,
                  )
              : null,
        );
      }
    } on SwapException catch (e) {
      // ส่งไม่ออก = เหตุจริงจาก WalletProvider ชัดกว่า "ถูกปฏิเสธหรือส่งไม่สำเร็จ"
      if (mounted) _showSnack(sendError ?? e.message(locale.isThai));
    } catch (_) {
      if (mounted) _showSnack(wallet.error ?? locale.t('common.error'));
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  /// กระเป๋าถูกสลับ/ตัดการเชื่อมต่อระหว่างที่ไม้ยังวิ่งอยู่ — หยุดก่อนส่งอะไรออกไป
  void _ensureSameWallet(WalletProvider wallet, String address) {
    final now = wallet.address;
    if (now == null || now.toLowerCase() != address.toLowerCase()) {
      throw const SwapException(
        'Wallet changed during the order — nothing was sent. Please try again.',
        'กระเป๋าถูกเปลี่ยนระหว่างส่งคำสั่ง — ยังไม่ได้ส่งอะไรออกไป ลองใหม่อีกครั้ง',
      );
    }
  }

  void _showSnack(
    String msg, {
    bool isSuccess = false,
    String? actionLabel,
    VoidCallback? onAction,
  }) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: isSuccess ? AppColors.tradingGreen : null,
        // มีปุ่ม action (เช่นลิงก์ดู tx) ให้ค้างนานขึ้นพอที่จะกด
        duration: Duration(seconds: actionLabel != null ? 8 : 2),
        action: actionLabel != null && onAction != null
            ? SnackBarAction(label: actionLabel, onPressed: onAction)
            : null,
      ),
    );
  }
}

// ── Buy / Sell segmented tab ──

class _SideTab extends StatelessWidget {
  final String label;
  final bool active;
  final bool isBuy;
  final VoidCallback onTap;

  const _SideTab({
    required this.label,
    required this.active,
    required this.isBuy,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accentColor =
        isBuy ? AppColors.tradingGreen : AppColors.tradingRed;
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 11),
        decoration: BoxDecoration(
          gradient:
              active ? (isBuy ? AppGradients.buy : AppGradients.sell) : null,
          borderRadius: BorderRadius.circular(11),
          boxShadow: active
              ? [
                  BoxShadow(
                    color: accentColor.withValues(alpha: 0.30),
                    blurRadius: 14,
                    offset: const Offset(0, 4),
                  ),
                ]
              : null,
        ),
        child: Center(
          child: Text(
            label,
            style: AppFonts.ui(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: active ? AppColors.white : AppColors.textTertiary,
            ),
          ),
        ),
      ),
    );
  }
}

// ── Gold timeframe pill ──

class _TimeframePill extends StatelessWidget {
  final String label;
  final bool active;
  final VoidCallback onTap;

  const _TimeframePill({
    required this.label,
    required this.active,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          gradient: active ? accent.goldGradient : null,
          color: active ? null : AppColors.bgInputStrong,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: active ? Colors.transparent : AppColors.bgCardBorder,
            width: 1,
          ),
        ),
        child: Text(
          label,
          style: AppFonts.ui(
            fontSize: 11.5,
            fontWeight: active ? FontWeight.w800 : FontWeight.w500,
            color: active ? AppColors.goldTextOn : AppColors.textTertiary,
          ),
        ),
      ),
    );
  }
}

// ── Quick percent chip ──

class _QuickChip extends StatelessWidget {
  final String label;
  final VoidCallback onTap;

  const _QuickChip({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    // Obsidian: ปุ่มเข้มนูน (สูตรปุ่ม 25% / 50% ของ Wallet) + ยุบตอนกด
    if (AppColors.palette.obsidian) {
      return ObsidianPressable(
        onTap: onTap,
        pressedScale: 0.95,
        child: CustomPaint(
          painter: const ObsidianSurfacePainter(ObsidianSurface.dark),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 9),
            child: Center(
              child: Text(
                label,
                style: AppFonts.ui(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
            ),
          ),
        ),
      );
    }
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: AppColors.bgInputStrong,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: AppColors.bgCardBorder),
        ),
        child: Text(
          label,
          style: AppFonts.ui(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
      ),
    );
  }
}

// ── Trade input field ──

class _TradeInput extends StatelessWidget {
  final String label;
  final TextEditingController controller;
  final String suffix;

  const _TradeInput({
    required this.label,
    required this.controller,
    required this.suffix,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final row = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14),
      child: _row(accent),
    );
    // Obsidian: ช่องกรอก "ยุบลง" (เงาจมด้านใน) แบบ Wallet
    if (AppColors.palette.obsidian) {
      return CustomPaint(
        painter: ObsidianSurfacePainter(
          ObsidianSurface.field.copyWith(radius: 14),
        ),
        child: row,
      );
    }
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgInput,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: row,
    );
  }

  Widget _row(AccentProvider accent) {
    return Row(
        children: [
          Text(label,
              style: AppFonts.ui(
                  fontSize: 12, color: AppColors.textTertiary)),
          const SizedBox(width: 8),
          Expanded(
            child: TextField(
              controller: controller,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              style: AppTheme.mono(fontSize: 14),
              textAlign: TextAlign.right,
              cursorColor: accent.g2,
              // ปิดกรอบ/พื้นของ InputDecorationTheme — ไม่งั้นเกิดกล่องซ้อนในกล่อง
              decoration: InputDecoration(
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
                filled: false,
                isDense: true,
                hintText: '0.00',
                hintStyle: TextStyle(color: AppColors.textDisabled),
                contentPadding: EdgeInsets.symmetric(vertical: 12),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Text(suffix,
              style: AppFonts.ui(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textSecondary)),
        ],
    );
  }
}

// ── Order book row ──

class _OrderBookRow extends StatelessWidget {
  final OrderBookEntry entry;
  final bool isBid;
  final double maxQty;

  const _OrderBookRow({
    required this.entry,
    required this.isBid,
    required this.maxQty,
  });

  @override
  Widget build(BuildContext context) {
    final fillRatio = maxQty > 0 ? entry.quantity / maxQty : 0.0;
    final barColor =
        isBid ? AppColors.tradingGreenBg : AppColors.tradingRedBg;
    final textColor = isBid ? AppColors.tradingGreen : AppColors.tradingRed;

    return Stack(
      children: [
        // Depth bar — bids fill from the left, asks fill from the right.
        Positioned.fill(
          child: Align(
            alignment: isBid ? Alignment.centerLeft : Alignment.centerRight,
            child: FractionallySizedBox(
              widthFactor: fillRatio.clamp(0, 1),
              child: Container(
                decoration: BoxDecoration(
                  color: barColor,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 3, horizontal: 2),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  entry.price.toStringAsFixed(2),
                  style: AppTheme.mono(
                      fontSize: 11, fontWeight: FontWeight.w700, color: textColor),
                ),
              ),
              Expanded(
                child: Text(
                  entry.quantity.toStringAsFixed(4),
                  style: AppTheme.mono(
                      fontSize: 11, color: AppColors.textSecondary),
                  textAlign: TextAlign.right,
                ),
              ),
              Expanded(
                child: Text(
                  entry.total.toStringAsFixed(2),
                  style: AppTheme.mono(
                      fontSize: 11, color: AppColors.textTertiary),
                  textAlign: TextAlign.right,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ── Open Order Row ──

class _OpenOrderRow extends StatelessWidget {
  final TradeOrder order;
  final LocaleProvider locale;

  /// null = ยกเลิกไม่ได้ (คำสั่งตั้งราคาที่ส่งธุรกรรมไปแล้ว รอผลบนเชน)
  final VoidCallback? onCancel;

  /// กำลังยกเลิกอยู่ — ปิดปุ่มกันกดซ้ำ
  final bool busy;

  const _OpenOrderRow({
    required this.order,
    required this.locale,
    required this.onCancel,
    this.busy = false,
  });

  String _fmt(double v) {
    if (v >= 1000) return v.toStringAsFixed(2);
    if (v >= 1) return v.toStringAsFixed(4);
    return v.toStringAsFixed(8).replaceFirst(RegExp(r'0+$'), '').replaceFirst(
        RegExp(r'\.$'), '');
  }

  /// ป้ายสถานะ — คำสั่งตั้งราคา: รอราคา / รอผลบนเชน · ชนิดเดิม: ไม่มีป้าย
  String? _statusLabel(bool th) {
    if (order.isWaitingForPrice) return th ? 'รอราคา' : 'Waiting for price';
    if (order.isAwaitingChain) {
      return th ? 'รอผลบนเชน' : 'Awaiting chain result';
    }
    return null;
  }

  String _typeLabel(bool th) => switch (order.type) {
        'limit' => 'Limit',
        'market' => 'Market',
        'stop-limit' => 'Stop-limit',
        _ => order.type,
      };

  @override
  Widget build(BuildContext context) {
    final th = locale.isThai;
    // ฝั่งที่ไม่ใช่ buy/sell = เป็นกลาง (ห้ามวาดเป็น "ขาย" สีแดง)
    final sideColor = switch (order.tradeSide) {
      TradeSide.buy => AppColors.tradingGreen,
      TradeSide.sell => AppColors.tradingRed,
      TradeSide.neutral => AppColors.textSecondary,
    };
    final sideLabel = switch (order.tradeSide) {
      TradeSide.buy => locale.t('trade.buy'),
      TradeSide.sell => locale.t('trade.sell'),
      TradeSide.neutral => th ? 'คำสั่ง' : 'Order',
    };
    final status = _statusLabel(th);
    final pairText = order.pairKey.replaceAll('-', '/');
    final parts = order.pairKey.split('-');
    final baseSym = parts.isNotEmpty ? parts.first : '';
    final quoteSym = parts.length > 1 ? parts[1] : '';
    final price = order.price;
    final priceText = price != null && price > 0
        ? '${_fmt(price)} $quoteSym'.trim()
        : (th ? 'ราคาตลาด' : 'market');

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            width: 6,
            height: 34,
            decoration: BoxDecoration(
              color: sideColor,
              borderRadius: BorderRadius.circular(3),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        '$sideLabel $pairText · ${_typeLabel(th)}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppFonts.ui(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w700,
                          color: sideColor,
                        ),
                      ),
                    ),
                    if (status != null) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 5, vertical: 1.5),
                        decoration: BoxDecoration(
                          color: AppColors.goldTint,
                          borderRadius: BorderRadius.circular(4),
                          border: Border.all(
                              color: AppColors.goldBorder, width: 0.6),
                        ),
                        child: Text(
                          status,
                          style: AppFonts.ui(
                            fontSize: 9,
                            fontWeight: FontWeight.w700,
                            color: AppColors.gold1,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  '${_fmt(order.amount)} $baseSym @ $priceText',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTheme.mono(
                      fontSize: 10.5, color: AppColors.textTertiary),
                ),
              ],
            ),
          ),
          if (onCancel != null) ...[
            const SizedBox(width: 8),
            GestureDetector(
              onTap: busy ? null : onCancel,
              behavior: HitTestBehavior.opaque,
              child: Opacity(
                opacity: busy ? 0.5 : 1,
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.tradingRedBg,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    locale.t('common.cancel'),
                    style: AppFonts.ui(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: AppColors.tradingRed),
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ── Pair Picker Bottom Sheet ──

class _PairPickerSheet extends StatefulWidget {
  final MarketProvider market;
  final ValueChanged<String> onSelect;

  const _PairPickerSheet({required this.market, required this.onSelect});

  @override
  State<_PairPickerSheet> createState() => _PairPickerSheetState();
}

class _PairPickerSheetState extends State<_PairPickerSheet> {
  String _search = '';

  List<Ticker> get _filtered {
    final q = _search.toUpperCase();
    if (q.isEmpty) return widget.market.allTickers;
    return widget.market.allTickers
        .where((t) => t.baseAsset.contains(q) || t.symbol.contains(q))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.7,
          ),
          decoration: BoxDecoration(
            color: AppColors.bgElevated,
            borderRadius:
                const BorderRadius.vertical(top: Radius.circular(24)),
            border: Border(top: BorderSide(color: accent.goldBorder, width: 1.2)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40,
                height: 4,
                margin: const EdgeInsets.only(top: 12, bottom: 16),
                decoration: BoxDecoration(
                  color: AppColors.textTertiary,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              // Search
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Container(
                  decoration: BoxDecoration(
                    color: AppColors.bgInput,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppColors.bgCardBorder),
                  ),
                  child: TextField(
                    onChanged: (v) => setState(() => _search = v),
                    cursorColor: accent.g2,
                    style: AppFonts.ui(
                        fontSize: 14, color: AppColors.textPrimary),
                    decoration: InputDecoration(
                      hintText: context
                          .read<LocaleProvider>()
                          .t('common.search_pairs'),
                      hintStyle: AppFonts.ui(
                          fontSize: 14, color: AppColors.textDisabled),
                      prefixIcon: Icon(Icons.search_rounded,
                          color: AppColors.textTertiary, size: 20),
                      border: InputBorder.none,
                      contentPadding:
                          const EdgeInsets.symmetric(vertical: 12),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              Flexible(
                child: ListView.builder(
                  itemCount: _filtered.length,
                  padding: const EdgeInsets.only(bottom: 24),
                  itemBuilder: (_, i) {
                    final t = _filtered[i];
                    final isSelected =
                        t.symbol == widget.market.selectedPair;
                    return ListTile(
                      dense: true,
                      selected: isSelected,
                      selectedTileColor: accent.goldTint,
                      leading: CoinLogo(
                        symbol: t.baseAsset,
                        size: 28,
                        borderRadius: 8,
                        logoUrl: t.isDex
                            ? widget.market.dexBaseLogo(t.symbol)
                            : context
                                .read<ConfigProvider>()
                                .pairBySymbol(t.symbol)
                                ?.baseLogo,
                        chainToken: t.isDex,
                      ),
                      title: Text(
                        t.displaySymbol,
                        style: AppFonts.ui(
                          fontSize: 14,
                          fontWeight: isSelected
                              ? FontWeight.w700
                              : FontWeight.w400,
                          color: isSelected
                              ? accent.g1
                              : AppColors.textPrimary,
                        ),
                      ),
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          PriceText(price: t.lastPrice, fontSize: 13),
                          ChangeBadge(
                              changePercent: t.priceChangePercent,
                              fontSize: 10),
                        ],
                      ),
                      onTap: () => widget.onSelect(t.symbol),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
