/// TPIX TRADE — TradingView Chart Widget
/// WebView wrapper สำหรับ lightweight-charts HTML
/// รองรับ candlestick, line, volume, MA(20), EMA(12)
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:webview_flutter/webview_flutter.dart';
import '../../core/theme/app_colors.dart';
import '../../widgets/common/shimmer_loading.dart';

class TradingChart extends StatefulWidget {
  final String symbol;
  final String interval;
  final bool isTpix;
  final double height;
  final ValueChanged<double>? onPriceUpdate;

  const TradingChart({
    super.key,
    required this.symbol,
    this.interval = '1h',
    this.isTpix = false,
    this.height = 300,
    this.onPriceUpdate,
  });

  @override
  State<TradingChart> createState() => TradingChartState();
}

class TradingChartState extends State<TradingChart> {
  late WebViewController _controller;
  bool _isReady = false;
  bool _isLoading = true;
  String? _htmlContent;

  @override
  void initState() {
    super.initState();
    _loadHtml();
  }

  Future<void> _loadHtml() async {
    final html = await rootBundle.loadString('assets/html/trading_chart.html');
    if (!mounted) return;
    setState(() => _htmlContent = html);
    _initWebView();
  }

  void _initWebView() {
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(const Color(0xFF0A0E1A))
      ..addJavaScriptChannel('FlutterChannel', onMessageReceived: _onMessage)
      ..setNavigationDelegate(NavigationDelegate(
        onPageFinished: (_) {
          _loadChartData();
        },
      ))
      ..loadHtmlString(_htmlContent!);
  }

  void _onMessage(JavaScriptMessage msg) {
    try {
      // ส่งมาเป็น JSON string
      if (msg.message.contains('ready')) {
        if (mounted) setState(() => _isReady = true);
      }
      if (msg.message.contains('priceUpdate') && widget.onPriceUpdate != null) {
        final priceMatch = RegExp(r'"price":([\d.]+)').firstMatch(msg.message);
        if (priceMatch != null) {
          widget.onPriceUpdate!(double.parse(priceMatch.group(1)!));
        }
      }
    } catch (_) {}
  }

  void _loadChartData() {
    final isTpix = widget.isTpix ? 'true' : 'false';
    _controller.runJavaScript(
      "loadChart('${widget.symbol}', '${widget.interval}', $isTpix)",
    );
    if (mounted) setState(() => _isLoading = false);
  }

  /// เปลี่ยน timeframe (เรียกจาก parent)
  void changeTimeframe(String interval) {
    final isTpix = widget.isTpix ? 'true' : 'false';
    _controller.runJavaScript(
      "loadChart('${widget.symbol}', '$interval', $isTpix)",
    );
  }

  /// เปลี่ยน chart type (candle / line)
  void setChartType(String type) {
    _controller.runJavaScript("setChartType('$type')");
  }

  /// เปลี่ยน indicators
  void setIndicators(List<String> indicators) {
    _controller.runJavaScript("setIndicators('${indicators.join(',')}')");
  }

  @override
  void didUpdateWidget(TradingChart old) {
    super.didUpdateWidget(old);
    if (old.symbol != widget.symbol || old.interval != widget.interval) {
      _loadChartData();
    }
  }

  @override
  void dispose() {
    try {
      _controller.runJavaScript('dispose()');
    } catch (_) {}
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_htmlContent == null) {
      return SizedBox(
        height: widget.height,
        child: const Center(child: ShimmerBox(width: 200, height: 14)),
      );
    }

    return SizedBox(
      height: widget.height,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Stack(
          children: [
            WebViewWidget(controller: _controller),
            if (_isLoading)
              Container(
                color: AppColors.bgCard,
                child: const Center(
                  child: ShimmerBox(width: 120, height: 14),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
