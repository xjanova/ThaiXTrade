/// TPIX TRADE — Mini Sparkline Widget
/// กราฟเส้นเล็กๆ แสดง trend 24 ชม. ของ pair ในรายการ markets
/// Lazy-load klines จาก Binance เก็บ cache ใน memory 5 นาที
///
/// Developed by Xman Studio

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import '../../core/theme/app_colors.dart';

class _SparklineCache {
  static final Map<String, _CachedData> _cache = {};
  static const _ttl = Duration(minutes: 5);

  static List<double>? get(String symbol) {
    final entry = _cache[symbol];
    if (entry == null) return null;
    if (DateTime.now().difference(entry.at) > _ttl) {
      _cache.remove(symbol);
      return null;
    }
    return entry.prices;
  }

  static void set(String symbol, List<double> prices) {
    _cache[symbol] = _CachedData(prices, DateTime.now());
  }
}

class _CachedData {
  final List<double> prices;
  final DateTime at;
  _CachedData(this.prices, this.at);
}

class MiniSparkline extends StatefulWidget {
  final String symbol; // e.g. "BTC-USDT"
  final double width;
  final double height;
  final bool isPositive;

  const MiniSparkline({
    super.key,
    required this.symbol,
    this.width = 60,
    this.height = 30,
    required this.isPositive,
  });

  @override
  State<MiniSparkline> createState() => _MiniSparklineState();
}

class _MiniSparklineState extends State<MiniSparkline> {
  static final _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 8),
    receiveTimeout: const Duration(seconds: 8),
  ));

  List<double>? _prices;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(MiniSparkline old) {
    super.didUpdateWidget(old);
    if (old.symbol != widget.symbol) _load();
  }

  Future<void> _load() async {
    // ใช้ cache ถ้ามี
    final cached = _SparklineCache.get(widget.symbol);
    if (cached != null) {
      setState(() => _prices = cached);
      return;
    }

    if (_loading) return;
    _loading = true;

    try {
      // Binance format: BTCUSDT
      final binSymbol = widget.symbol.replaceAll('-', '').toUpperCase();
      final url =
          'https://api.binance.com/api/v3/klines?symbol=$binSymbol&interval=1h&limit=24';
      final res = await _dio.get(url);

      if (res.statusCode == 200 && res.data is List) {
        final prices = (res.data as List)
            .map((k) => double.tryParse(k[4].toString()) ?? 0)
            .where((p) => p > 0)
            .toList();

        if (prices.isNotEmpty) {
          _SparklineCache.set(widget.symbol, prices);
          if (mounted) setState(() => _prices = prices);
        }
      }
    } catch (e) {
      debugPrint('Sparkline ${widget.symbol}: ${e.runtimeType}');
    }

    _loading = false;
  }

  @override
  Widget build(BuildContext context) {
    final prices = _prices;
    if (prices == null || prices.length < 2) {
      return SizedBox(width: widget.width, height: widget.height);
    }

    return SizedBox(
      width: widget.width,
      height: widget.height,
      child: CustomPaint(
        painter: _SparklinePainter(
          prices: prices,
          color: widget.isPositive
              ? AppColors.tradingGreen
              : AppColors.tradingRed,
        ),
      ),
    );
  }
}

class _SparklinePainter extends CustomPainter {
  final List<double> prices;
  final Color color;

  _SparklinePainter({required this.prices, required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    if (prices.length < 2) return;

    final minP = prices.reduce((a, b) => a < b ? a : b);
    final maxP = prices.reduce((a, b) => a > b ? a : b);
    final range = maxP - minP;
    if (range == 0) return;

    final path = Path();
    final fillPath = Path();

    for (int i = 0; i < prices.length; i++) {
      final x = (i / (prices.length - 1)) * size.width;
      final y = size.height - ((prices[i] - minP) / range) * (size.height - 4) - 2;

      if (i == 0) {
        path.moveTo(x, y);
        fillPath.moveTo(x, size.height);
        fillPath.lineTo(x, y);
      } else {
        path.lineTo(x, y);
        fillPath.lineTo(x, y);
      }
    }

    fillPath.lineTo(size.width, size.height);
    fillPath.close();

    // Fill gradient
    final fillPaint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [color.withValues(alpha: 0.25), color.withValues(alpha: 0)],
      ).createShader(Rect.fromLTWH(0, 0, size.width, size.height));
    canvas.drawPath(fillPath, fillPaint);

    // Line
    final linePaint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5
      ..strokeCap = StrokeCap.round;
    canvas.drawPath(path, linePaint);
  }

  @override
  bool shouldRepaint(covariant _SparklinePainter old) =>
      old.prices.length != prices.length ||
      old.color != color ||
      (prices.isNotEmpty && old.prices.last != prices.last);
}
