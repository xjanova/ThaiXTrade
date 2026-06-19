/// TPIX TRADE — Strategy Engine
/// Pure-Dart, dependency-free technical-analysis engine that turns OHLCV
/// candles into a BUY/SELL/HOLD signal via a multi-indicator CONFLUENCE:
///   • EMA 9/21 trend + crossover   (weight 0.35)
///   • RSI(14, Wilder)              (weight 0.30)
///   • MACD(12,26,9) histogram      (weight 0.25)
///   • Volume surge confirmation    (weight 0.10)
///
/// This is RULE-BASED technical analysis — NOT a trained AI model and NOT
/// financial advice. It only reads public market candles; it never fabricates
/// data. Kept free of Flutter imports so it is trivially unit-testable.
///
/// Developed by Xman Studio

library;

import 'dart:math' as math;

/// One OHLCV bar.
class Candle {
  final double open;
  final double high;
  final double low;
  final double close;
  final double volume;

  const Candle({
    required this.open,
    required this.high,
    required this.low,
    required this.close,
    required this.volume,
  });
}

/// Discrete verdict, strongest → weakest in both directions.
enum SignalAction { strongBuy, buy, hold, sell, strongSell }

extension SignalActionX on SignalAction {
  bool get isBuy =>
      this == SignalAction.strongBuy || this == SignalAction.buy;
  bool get isSell =>
      this == SignalAction.strongSell || this == SignalAction.sell;
  bool get isStrong =>
      this == SignalAction.strongBuy || this == SignalAction.strongSell;

  /// 'buy' / 'sell' for the order API, or null for hold.
  String? get orderSide => isBuy ? 'buy' : (isSell ? 'sell' : null);
}

/// Result of evaluating a candle series.
class StrategySignal {
  final SignalAction action;

  /// 0..1 — strength of the verdict (= |score|).
  final double confidence;

  /// -1..1 — raw confluence score (positive = bullish).
  final double score;

  /// Last close used.
  final double lastClose;

  /// Human-readable reasons (English + Thai), most-significant first.
  final List<String> reasons;
  final List<String> reasonsTh;

  /// Raw indicator values for display/debug: rsi, macd, macdSignal, macdHist,
  /// ema9, ema21, volRatio.
  final Map<String, double> indicators;

  const StrategySignal({
    required this.action,
    required this.confidence,
    required this.score,
    required this.lastClose,
    required this.reasons,
    required this.reasonsTh,
    required this.indicators,
  });

  int get confidencePercent => (confidence * 100).round();

  /// A neutral / not-enough-data signal.
  factory StrategySignal.neutral({double lastClose = 0}) => StrategySignal(
        action: SignalAction.hold,
        confidence: 0,
        score: 0,
        lastClose: lastClose,
        reasons: const ['Not enough data'],
        reasonsTh: const ['ข้อมูลไม่พอ'],
        indicators: const {},
      );
}

class StrategyEngine {
  StrategyEngine._();

  /// Minimum candles needed for a meaningful signal (MACD slow 26 + signal 9).
  static const int minCandles = 35;

  // ── Indicators ─────────────────────────────────

  /// Exponential moving average series (seeded with the first value).
  /// Returns a list the same length as [v]; never NaN.
  static List<double> ema(List<double> v, int period) {
    if (v.isEmpty) return const [];
    final k = 2.0 / (period + 1);
    final out = List<double>.filled(v.length, 0.0);
    out[0] = v[0];
    for (int i = 1; i < v.length; i++) {
      out[i] = v[i] * k + out[i - 1] * (1 - k);
    }
    return out;
  }

  /// RSI using Wilder's smoothing. Returns 0..100 (50 if insufficient data).
  static double rsi(List<double> closes, [int period = 14]) {
    if (closes.length < period + 1) return 50;
    double gain = 0, loss = 0;
    for (int i = 1; i <= period; i++) {
      final d = closes[i] - closes[i - 1];
      if (d >= 0) {
        gain += d;
      } else {
        loss -= d;
      }
    }
    double avgGain = gain / period;
    double avgLoss = loss / period;
    for (int i = period + 1; i < closes.length; i++) {
      final d = closes[i] - closes[i - 1];
      final g = d > 0 ? d : 0.0;
      final l = d < 0 ? -d : 0.0;
      avgGain = (avgGain * (period - 1) + g) / period;
      avgLoss = (avgLoss * (period - 1) + l) / period;
    }
    if (avgLoss == 0) return avgGain == 0 ? 50 : 100;
    final rs = avgGain / avgLoss;
    return 100 - 100 / (1 + rs);
  }

  /// MACD line / signal / histogram (last) + previous histogram (for slope).
  static ({double line, double signal, double hist, double histPrev}) macd(
    List<double> closes, {
    int fast = 12,
    int slow = 26,
    int sig = 9,
  }) {
    if (closes.length < slow + sig) {
      return (line: 0, signal: 0, hist: 0, histPrev: 0);
    }
    final ef = ema(closes, fast);
    final es = ema(closes, slow);
    final macdLine =
        List<double>.generate(closes.length, (i) => ef[i] - es[i]);
    final signalSeries = ema(macdLine, sig);
    final n = closes.length;
    return (
      line: macdLine[n - 1],
      signal: signalSeries[n - 1],
      hist: macdLine[n - 1] - signalSeries[n - 1],
      histPrev: macdLine[n - 2] - signalSeries[n - 2],
    );
  }

  /// Ratio of the last bar's volume to the average of the previous [period].
  /// 1.0 = average; >1 = above-average volume.
  static double volumeRatio(List<double> volumes, [int period = 20]) {
    if (volumes.length < 2) return 1;
    final p = math.min(period, volumes.length - 1);
    double sum = 0;
    for (int i = volumes.length - 1 - p; i < volumes.length - 1; i++) {
      sum += volumes[i];
    }
    final avg = sum / p;
    if (avg <= 0) return 1;
    return volumes.last / avg;
  }

  // ── Confluence ─────────────────────────────────

  static StrategySignal evaluate(List<Candle> candles) {
    if (candles.length < minCandles) {
      return StrategySignal.neutral(
        lastClose: candles.isEmpty ? 0 : candles.last.close,
      );
    }

    final closes = [for (final c in candles) c.close];
    final volumes = [for (final c in candles) c.volume];

    final e9 = ema(closes, 9);
    final e21 = ema(closes, 21);
    final ema9 = e9.last, ema21 = e21.last;
    final ema9Prev = e9[e9.length - 2], ema21Prev = e21[e21.length - 2];
    final r = rsi(closes, 14);
    final m = macd(closes);
    final vr = volumeRatio(volumes, 20);
    final lastClose = closes.last;

    double score = 0;
    final reasons = <String>[];
    final reasonsTh = <String>[];

    // ── EMA trend + crossover (0.35) ──
    // Relative deadband so flat/near-equal EMAs are neutral, not falsely trended.
    final emaDiff = ema21 != 0 ? (ema9 - ema21) / ema21 : 0.0;
    const emaBand = 0.0005; // 5 bps
    final crossedUp = ema9Prev <= ema21Prev && ema9 > ema21;
    final crossedDown = ema9Prev >= ema21Prev && ema9 < ema21;
    if (crossedUp) {
      score += 0.35;
      reasons.add('EMA 9/21 bullish cross');
      reasonsTh.add('EMA 9/21 ตัดขึ้น');
    } else if (crossedDown) {
      score -= 0.35;
      reasons.add('EMA 9/21 bearish cross');
      reasonsTh.add('EMA 9/21 ตัดลง');
    } else if (emaDiff > emaBand) {
      score += 0.18;
      reasons.add('Above EMA trend');
      reasonsTh.add('อยู่เหนือเส้นเทรนด์');
    } else if (emaDiff < -emaBand) {
      score -= 0.18;
      reasons.add('Below EMA trend');
      reasonsTh.add('อยู่ใต้เส้นเทรนด์');
    }

    // ── RSI (0.30) ──
    final rr = r.round();
    if (r < 30) {
      score += 0.30;
      reasons.add('RSI $rr oversold');
      reasonsTh.add('RSI $rr oversold');
    } else if (r > 70) {
      score -= 0.30;
      reasons.add('RSI $rr overbought');
      reasonsTh.add('RSI $rr overbought');
    } else if (r >= 50) {
      score += 0.10;
      reasons.add('RSI $rr bullish');
      reasonsTh.add('RSI $rr ฝั่งขึ้น');
    } else {
      score -= 0.10;
      reasons.add('RSI $rr bearish');
      reasonsTh.add('RSI $rr ฝั่งลง');
    }

    // ── MACD (0.25) — hist == 0 contributes nothing (neutral) ──
    final histRising = m.hist > m.histPrev;
    if (m.hist > 0) {
      score += histRising ? 0.25 : 0.12;
      reasons.add(histRising ? 'MACD rising' : 'MACD positive');
      reasonsTh.add(histRising ? 'MACD เพิ่มขึ้น' : 'MACD เป็นบวก');
    } else if (m.hist < 0) {
      score += histRising ? -0.12 : -0.25;
      reasons.add(histRising ? 'MACD negative' : 'MACD falling');
      reasonsTh.add(histRising ? 'MACD เป็นลบ' : 'MACD ลดลง');
    }

    // ── Volume confirmation (0.10) — amplifies the prevailing direction ──
    if (vr > 1.5 && score != 0) {
      final dir = score > 0 ? 1 : -1;
      score += dir * 0.10;
      final pct = ((vr - 1) * 100).round();
      reasons.add('Volume +$pct%');
      reasonsTh.add('วอลุ่ม +$pct%');
    }

    score = score.clamp(-1.0, 1.0);

    final SignalAction action;
    if (score >= 0.55) {
      action = SignalAction.strongBuy;
    } else if (score >= 0.20) {
      action = SignalAction.buy;
    } else if (score <= -0.55) {
      action = SignalAction.strongSell;
    } else if (score <= -0.20) {
      action = SignalAction.sell;
    } else {
      action = SignalAction.hold;
    }

    return StrategySignal(
      action: action,
      confidence: score.abs().clamp(0.0, 1.0),
      score: score,
      lastClose: lastClose,
      reasons: reasons,
      reasonsTh: reasonsTh,
      indicators: {
        'rsi': r,
        'macd': m.line,
        'macdSignal': m.signal,
        'macdHist': m.hist,
        'ema9': ema9,
        'ema21': ema21,
        'volRatio': vr,
      },
    );
  }
}
