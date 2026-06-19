import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/strategy_engine.dart';

List<Candle> _candlesFromCloses(List<double> closes, {double volume = 1000}) =>
    [for (final c in closes) Candle(open: c, high: c, low: c, close: c, volume: volume)];

void main() {
  group('EMA', () {
    test('seeded with first value, classic recurrence', () {
      final e = StrategyEngine.ema([10, 11, 12], 2);
      // k = 2/3; e0=10; e1=11*2/3+10/3=10.6667; e2=12*2/3+10.6667/3=11.5556
      expect(e[0], closeTo(10.0, 1e-9));
      expect(e[1], closeTo(10.6667, 1e-3));
      expect(e[2], closeTo(11.5556, 1e-3));
    });

    test('empty input → empty', () {
      expect(StrategyEngine.ema(const [], 9), isEmpty);
    });
  });

  group('RSI (Wilder)', () {
    test('monotonic gains → 100', () {
      final closes = [for (int i = 1; i <= 20; i++) i.toDouble()];
      expect(StrategyEngine.rsi(closes), 100);
    });

    test('monotonic losses → 0', () {
      final closes = [for (int i = 20; i >= 1; i--) i.toDouble()];
      expect(StrategyEngine.rsi(closes), 0);
    });

    test('insufficient data → 50 (neutral)', () {
      expect(StrategyEngine.rsi([1, 2, 3]), 50);
    });
  });

  group('MACD', () {
    test('insufficient data → zeros', () {
      final m = StrategyEngine.macd([1, 2, 3, 4]);
      expect(m.line, 0);
      expect(m.signal, 0);
      expect(m.hist, 0);
    });

    test('uptrend → positive MACD line (fast EMA above slow)', () {
      final closes = [for (int i = 0; i < 61; i++) (100 + i).toDouble()];
      final m = StrategyEngine.macd(closes);
      expect(m.line, greaterThan(0));
    });

    test('downtrend → negative MACD line', () {
      final closes = [for (int i = 0; i < 61; i++) (160 - i).toDouble()];
      final m = StrategyEngine.macd(closes);
      expect(m.line, lessThan(0));
    });
  });

  group('volumeRatio', () {
    test('last bar double the average → 2.0', () {
      expect(StrategyEngine.volumeRatio([1, 1, 1, 1, 2], 4), closeTo(2.0, 1e-9));
    });

    test('too few bars → 1.0', () {
      expect(StrategyEngine.volumeRatio([5], 20), 1.0);
    });
  });

  group('evaluate', () {
    test('insufficient candles → neutral HOLD, confidence 0', () {
      final s = StrategyEngine.evaluate(_candlesFromCloses([1, 2, 3, 4, 5]));
      expect(s.action, SignalAction.hold);
      expect(s.confidence, 0);
    });

    test('strong uptrend flags RSI overbought', () {
      final closes = [for (int i = 0; i < 61; i++) (100 + i).toDouble()];
      final s = StrategyEngine.evaluate(_candlesFromCloses(closes));
      expect(s.reasons.any((r) => r.contains('overbought')), isTrue);
      expect(s.indicators['rsi'], greaterThan(70));
    });

    test('strong downtrend flags RSI oversold', () {
      final closes = [for (int i = 0; i < 61; i++) (160 - i).toDouble()];
      final s = StrategyEngine.evaluate(_candlesFromCloses(closes));
      expect(s.reasons.any((r) => r.contains('oversold')), isTrue);
      expect(s.indicators['rsi'], lessThan(30));
    });

    test('score in [-1,1], confidence = |score|, action↔score consistent', () {
      // Mixed wave so confluence is non-trivial.
      final closes = [
        for (int i = 0; i < 80; i++)
          100 + 8 * (i % 20 < 10 ? i % 10 : 10 - (i % 10)).toDouble()
      ];
      final s = StrategyEngine.evaluate(_candlesFromCloses(closes));
      expect(s.score, inInclusiveRange(-1.0, 1.0));
      expect(s.confidence, closeTo(s.score.abs(), 1e-9));
      switch (s.action) {
        case SignalAction.strongBuy:
          expect(s.score, greaterThanOrEqualTo(0.55));
          break;
        case SignalAction.buy:
          expect(s.score, inInclusiveRange(0.20, 0.55));
          break;
        case SignalAction.hold:
          expect(s.score, inInclusiveRange(-0.20, 0.20));
          break;
        case SignalAction.sell:
          expect(s.score, inInclusiveRange(-0.55, -0.20));
          break;
        case SignalAction.strongSell:
          expect(s.score, lessThanOrEqualTo(-0.55));
          break;
      }
    });

    test('orderSide maps buy/sell/hold correctly', () {
      expect(SignalAction.strongBuy.orderSide, 'buy');
      expect(SignalAction.sell.orderSide, 'sell');
      expect(SignalAction.hold.orderSide, isNull);
    });
  });
}
