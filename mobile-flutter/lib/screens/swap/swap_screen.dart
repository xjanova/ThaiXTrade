/// TPIX TRADE — Swap Screen (Luxury Dark / Gilded Metal)
/// Token-to-token swap on the gunmetal+gold backdrop. "You pay" / "You receive"
/// cards with a centered gold flip button between them, a live quote pulled from
/// the real /swap/quote endpoint (debounced), and a details card whose rows are
/// populated ONLY from fields the backend actually returns — no invented numbers.
///
/// There is no on-chain swap-execution path in services yet, so "Swap Now"
/// confirms the live quote in a review sheet rather than faking a settled swap.
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/config_provider.dart';
import '../../services/api_service.dart';
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
  final _api = ApiService();

  // Selected swap tokens (symbols). Defaults give a meaningful first pair.
  String _fromToken = 'TPIX';
  String _toToken = 'USDT';

  double _slippage = 0.5; // %

  // Quote state
  Map<String, dynamic>? _quote;
  bool _quoting = false;
  String? _quoteError;
  int _quoteSeq = 0; // guards out-of-order async responses
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    // Seed slippage from the user's saved wallet preference.
    final wallet = context.read<WalletProvider>();
    _slippage = wallet.slippage;
    _payController.addListener(_onAmountChanged);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _payController.removeListener(_onAmountChanged);
    _payController.dispose();
    super.dispose();
  }

  // ── Token universe ──
  // Build a real, de-duplicated token list from the connected wallet balances
  // plus the active chain's known tokens (native + USDT/USDC) and TPIX.
  List<String> _tokenUniverse(WalletProvider wallet) {
    final set = <String>{};
    for (final b in wallet.balances) {
      if (b.symbol.isNotEmpty) set.add(b.symbol.toUpperCase());
    }
    for (final t in wallet.activeChain.allTokens) {
      if (t.symbol.isNotEmpty) set.add(t.symbol.toUpperCase());
    }
    // Always offer TPIX + common quotes so an empty/disconnected wallet still
    // has something to pick.
    set.addAll(const ['TPIX', 'USDT', 'USDC']);
    final list = set.toList()..sort();
    return list;
  }

  double? _balanceOf(WalletProvider wallet, String symbol) {
    for (final b in wallet.balances) {
      if (b.symbol.toUpperCase() == symbol.toUpperCase()) return b.balance;
    }
    return null;
  }

  // ── Quote fetching (debounced) ──

  void _onAmountChanged() {
    setState(() {}); // refresh balance/usd lines + button enabled state
    _debounce?.cancel();
    final amount = double.tryParse(_payController.text.trim());
    if (amount == null || amount <= 0) {
      // Clear any stale quote when the field is emptied / invalid.
      if (_quote != null || _quoteError != null || _quoting) {
        setState(() {
          _quote = null;
          _quoteError = null;
          _quoting = false;
        });
      }
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 550), _fetchQuote);
  }

  Future<void> _fetchQuote() async {
    final amount = double.tryParse(_payController.text.trim());
    if (amount == null || amount <= 0) return;
    if (_fromToken == _toToken) {
      setState(() {
        _quote = null;
        _quoteError = null;
        _quoting = false;
      });
      return;
    }

    final wallet = context.read<WalletProvider>();
    final seq = ++_quoteSeq;
    setState(() {
      _quoting = true;
      _quoteError = null;
    });

    Map<String, dynamic>? res;
    try {
      res = await _api.getSwapQuote(
        fromToken: _fromToken,
        toToken: _toToken,
        amount: amount,
        chainId: wallet.activeChainId,
        slippage: _slippage,
      );
    } catch (_) {
      res = null;
    }

    // Guard: a newer request superseded this one, or the screen is gone.
    if (!mounted || seq != _quoteSeq) return;

    setState(() {
      _quoting = false;
      _quote = res;
      _quoteError = res == null
          ? (context.read<LocaleProvider>().isThai
              ? 'ดึงราคาไม่สำเร็จ ลองอีกครั้ง'
              : 'Could not fetch quote. Try again.')
          : null;
    });
  }

  // ── Direction flip ──

  void _flipDirection() {
    final wasFrom = _fromToken;
    setState(() {
      _fromToken = _toToken;
      _toToken = wasFrom;
      _quote = null;
      _quoteError = null;
    });
    _onAmountChanged();
  }

  // ── Token picker ──

  Future<void> _pickToken({required bool isFrom}) async {
    final wallet = context.read<WalletProvider>();
    final locale = context.read<LocaleProvider>();
    final tokens = _tokenUniverse(wallet);

    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _TokenPickerSheet(
        tokens: tokens,
        balanceOf: (s) => _balanceOf(wallet, s),
        selected: isFrom ? _fromToken : _toToken,
        disabled: isFrom ? _toToken : _fromToken,
        locale: locale,
      ),
    );

    if (picked == null || !mounted) return;
    setState(() {
      if (isFrom) {
        _fromToken = picked;
      } else {
        _toToken = picked;
      }
      _quote = null;
      _quoteError = null;
    });
    _onAmountChanged();
  }

  // ── Slippage dialog ──

  Future<void> _openSlippageDialog() async {
    final locale = context.read<LocaleProvider>();
    final picked = await showDialog<double>(
      context: context,
      builder: (_) => _SlippageDialog(current: _slippage, locale: locale),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _slippage = picked;
      _quote = null;
    });
    _onAmountChanged();
  }

  // ── Review (no real execution path yet) ──

  void _reviewSwap() {
    final locale = context.read<LocaleProvider>();
    final amount = double.tryParse(_payController.text.trim());
    if (amount == null || amount <= 0) {
      _snack(locale.isThai ? 'กรอกจำนวนให้ถูกต้อง' : 'Enter a valid amount');
      return;
    }
    if (_quote == null) {
      _snack(locale.isThai ? 'รอราคาก่อน' : 'Wait for the quote');
      return;
    }
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _ReviewSheet(
        fromToken: _fromToken,
        toToken: _toToken,
        payAmount: amount,
        receiveAmount: _receiveAmount(),
        quote: _quote!,
        slippage: _slippage,
        locale: locale,
      ),
    );
  }

  void _snack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), duration: const Duration(seconds: 2)),
    );
  }

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
    // market watched so USD ≈ lines refresh as live prices stream in.
    context.watch<MarketProvider>();

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
                  padding: const EdgeInsets.fromLTRB(18, 6, 18, 0),
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
                  child: _buildCta(locale, wallet),
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
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Column(
          children: [
            _PayCard(
              label: locale.isThai ? 'คุณจ่าย' : 'You pay',
              token: _fromToken,
              controller: _payController,
              balance: _balanceOf(wallet, _fromToken),
              usdValue: _usdEstimate(_fromToken, _payAmountRaw()),
              onPickToken: () => _pickToken(isFrom: true),
              onMax: () => _applyMax(wallet),
              locale: locale,
            ),
            const SizedBox(height: 10),
            _ReceiveCard(
              label: locale.isThai ? 'คุณได้รับ' : 'You receive',
              token: _toToken,
              amount: _receiveAmount(),
              quoting: _quoting,
              usdValue: _usdEstimate(_toToken, _receiveAmount()),
              onPickToken: () => _pickToken(isFrom: false),
              locale: locale,
            ),
          ],
        ),
        // Centered gold flip button overlapping the seam between the two cards.
        Positioned.fill(
          child: Align(
            alignment: Alignment.center,
            child: _FlipButton(onTap: _flipDirection),
          ),
        ),
      ],
    );
  }

  double? _payAmountRaw() => double.tryParse(_payController.text.trim());

  void _applyMax(WalletProvider wallet) {
    final bal = _balanceOf(wallet, _fromToken);
    if (bal == null || bal <= 0) return;
    _payController.text = _trimNum(bal);
    _payController.selection = TextSelection.fromPosition(
      TextPosition(offset: _payController.text.length),
    );
  }

  // USD estimate from live market tickers (real data; null if unknown).
  double? _usdEstimate(String symbol, double? amount) {
    if (amount == null || amount <= 0) return null;
    final s = symbol.toUpperCase();
    if (s == 'USDT' || s == 'USDC' || s == 'BUSD' || s == 'DAI') {
      return amount; // stablecoins ≈ 1 USD
    }
    final market = context.read<MarketProvider>();
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
    final rows = <Widget>[];

    // Rate — prefer an explicit rate field; otherwise derive from amounts.
    final rate = _readNum(_quote, const ['rate', 'price', 'exchange_rate']) ??
        _derivedRate();
    if (rate != null) {
      rows.add(_DetailRow(
        label: locale.isThai ? 'อัตราแลกเปลี่ยน' : 'Rate',
        value: '1 $_fromToken ≈ ${_trimNum(rate)} $_toToken',
      ));
    }

    final platformFee = _readNum(_quote, const [
      'platform_fee',
      'platformFee',
      'fee',
      'swap_fee',
      'swapFee',
    ]);
    if (platformFee != null) {
      rows.add(_DetailRow(
        label: locale.isThai ? 'ค่าธรรมเนียมแพลตฟอร์ม' : 'Platform fee',
        value: _trimNum(platformFee),
      ));
    } else {
      // Fall back to the chain swap fee % from real config, clearly labelled %.
      final feePct = context.read<ConfigProvider>().swapFeePercent;
      rows.add(_DetailRow(
        label: locale.isThai ? 'ค่าธรรมเนียมแพลตฟอร์ม' : 'Platform fee',
        value: '${_trimNum(feePct)}%',
      ));
    }

    final networkFee = _readNum(_quote, const [
      'network_fee',
      'networkFee',
      'gas_fee',
      'gasFee',
      'gas',
    ]);
    if (networkFee != null) {
      rows.add(_DetailRow(
        label: locale.isThai ? 'ค่าธรรมเนียมเครือข่าย' : 'Network fee',
        value: _trimNum(networkFee),
      ));
    } else if (context.read<WalletProvider>().activeChain.isGasless) {
      // TPIX Chain is gasless — this is a real property, not a guess.
      rows.add(_DetailRow(
        label: locale.isThai ? 'ค่าธรรมเนียมเครือข่าย' : 'Network fee',
        value: locale.isThai ? 'ไม่มี (Zero gas)' : 'None (Zero gas)',
        valueColor: AppColors.gold1,
      ));
    }

    // Minimum received (after slippage) — only if backend returns it.
    final minReceived = _readNum(_quote, const [
      'min_received',
      'minReceived',
      'minimum_received',
      'min_amount_out',
      'minAmountOut',
    ]);
    if (minReceived != null) {
      rows.add(_DetailRow(
        label: locale.isThai ? 'ได้รับขั้นต่ำ' : 'Min. received',
        value: '${_trimNum(minReceived)} $_toToken',
      ));
    }

    // Price impact — only if backend returns it.
    final priceImpact =
        _readNum(_quote, const ['price_impact', 'priceImpact', 'impact']);
    if (priceImpact != null) {
      rows.add(_DetailRow(
        label: locale.isThai ? 'ผลกระทบราคา' : 'Price impact',
        value: '${_trimNum(priceImpact)}%',
      ));
    }

    rows.add(_DetailRow(
      label: locale.isThai ? 'ค่าคลาดเคลื่อน (Slippage)' : 'Slippage',
      value: '${_trimNum(_slippage)}%',
    ));

    // Route — render only when the backend actually returns a path.
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
                locale.isThai ? 'รายละเอียด' : 'Details',
                style: GoogleFonts.inter(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const Spacer(),
              if (_quoting)
                const SizedBox(
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
          if (routeStr != null) ...[
            const SizedBox(height: 10),
            const Divider(color: AppColors.divider, height: 1),
            const SizedBox(height: 10),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  locale.isThai ? 'เส้นทาง' : 'Route',
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

  Widget _buildCta(LocaleProvider locale, WalletProvider wallet) {
    final amount = _payAmountRaw();
    final hasAmount = amount != null && amount > 0;
    final ready = wallet.isConnected &&
        hasAmount &&
        _fromToken != _toToken &&
        _quote != null &&
        !_quoting;

    final label = !wallet.isConnected
        ? (locale.isThai ? 'เชื่อมกระเป๋าก่อน' : 'Connect wallet first')
        : (locale.isThai ? 'สลับตอนนี้' : 'Swap Now');

    return Column(
      children: [
        GradientButton(
          text: label,
          variant: ButtonVariant.gold,
          icon: Icons.swap_horiz_rounded,
          isLoading: _quoting && hasAmount,
          onPressed: ready ? _reviewSwap : null,
        ),
        const SizedBox(height: 10),
        Text(
          locale.isThai
              ? 'ราคาจริงจาก TPIX • อาจเปลี่ยนแปลงได้ตามตลาด'
              : 'Live quote from TPIX • subject to market movement',
          style: GoogleFonts.inter(
            fontSize: 10.5,
            color: AppColors.textTertiary,
          ),
          textAlign: TextAlign.center,
        ),
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
  final TextEditingController controller;
  final double? balance;
  final double? usdValue;
  final VoidCallback onPickToken;
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
              _TokenPill(symbol: token, onTap: onPickToken),
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
  final double? amount;
  final bool quoting;
  final double? usdValue;
  final VoidCallback onPickToken;
  final LocaleProvider locale;

  const _ReceiveCard({
    required this.label,
    required this.token,
    required this.amount,
    required this.quoting,
    required this.usdValue,
    required this.onPickToken,
    required this.locale,
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
              _TokenPill(symbol: token, onTap: onPickToken),
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
  final VoidCallback onTap;

  const _TokenPill({required this.symbol, required this.onTap});

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
            CoinChip(symbol: symbol, size: 28),
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
            const Icon(Icons.keyboard_arrow_down_rounded,
                size: 18, color: AppColors.textSecondary),
          ],
        ),
      ),
    );
  }
}

// ── Centered gold flip button ──

class _FlipButton extends StatelessWidget {
  final VoidCallback onTap;
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
        child: const Center(
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

  const _TokenPickerSheet({
    required this.tokens,
    required this.balanceOf,
    required this.selected,
    required this.disabled,
    required this.locale,
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
      decoration: const BoxDecoration(
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
                  icon: const Icon(Icons.close_rounded,
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
                  const Icon(Icons.search_rounded,
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
                          leading: CoinChip(symbol: sym, size: 38),
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
                              ? const Icon(Icons.check_circle_rounded,
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
        side: const BorderSide(color: AppColors.goldBorder),
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
              style: const TextStyle(color: AppColors.textTertiary)),
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
              style: const TextStyle(color: AppColors.goldTextOn)),
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

// ── Review sheet (quote confirmation — not a settled swap) ──

class _ReviewSheet extends StatelessWidget {
  final String fromToken;
  final String toToken;
  final double payAmount;
  final double? receiveAmount;
  final Map<String, dynamic> quote;
  final double slippage;
  final LocaleProvider locale;

  const _ReviewSheet({
    required this.fromToken,
    required this.toToken,
    required this.payAmount,
    required this.receiveAmount,
    required this.quote,
    required this.slippage,
    required this.locale,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Container(
        decoration: const BoxDecoration(
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
                  CoinChip(symbol: fromToken, size: 34),
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
                  CoinChip(symbol: toToken, size: 34),
                  locale.isThai ? 'รับ' : 'Receive',
                  receiveAmount != null
                      ? '${_fmt(receiveAmount!)} $toToken'
                      : '—',
                  valueColor: AppColors.gold1,
                ),
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.goldTint,
                    borderRadius: BorderRadius.circular(12),
                    border:
                        Border.all(color: AppColors.goldBorder, width: 1),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline_rounded,
                          size: 16, color: AppColors.gold2),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          locale.isThai
                              ? 'การลงนามสลับบนเชนกำลังจะเปิดให้บริการเร็วๆ นี้ — นี่คือราคาจริงจากระบบ'
                              : 'On-chain swap signing is coming soon — this is a live quote, not a settled trade.',
                          style: GoogleFonts.inter(
                            fontSize: 11.5,
                            color: AppColors.textSecondary,
                            height: 1.35,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                GradientButton(
                  text: locale.isThai ? 'รับทราบ' : 'Got it',
                  variant: ButtonVariant.gold,
                  onPressed: () => Navigator.pop(context),
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
