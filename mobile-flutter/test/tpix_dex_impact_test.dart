/// TPIX TRADE — price impact บนพูล TPIX DEX + จำนวนสูงสุดที่ส่งได้
///
/// สิ่งที่กันไว้:
///   - สูตร price impact ต้องตรงกับเว็บ (useTpixDex.js) เป๊ะ — เพดาน 10% เท่ากันทุกแพลตฟอร์ม
///   - ไม้ที่โดนบล็อกต้องบอก "สั่งได้สูงสุดเท่าไร" และจำนวนนั้นต้องผ่านเพดานจริง
///   - ตัวเลขที่โชว์/เติมให้ต้องปัดลงเสมอ (ปัดขึ้นแล้วกดตามจะโดนบล็อกอีก)
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart' show VerifiedToken;
import 'package:tpix_trade/services/tpix_dex_service.dart';

BigInt _wei(String v) => BigInt.parse(v);

// พูลจริงตอนเขียนเทสต์: ≈199.9 TPIX / 19.99 USDT (ราคา 0.10)
final _rTpix = _wei('199900000000000000000'); // 18 ตำแหน่ง
final _rUsdt = _wei('19990000'); // 6 ตำแหน่ง

const _tpix = VerifiedToken(
  symbol: 'TPIX',
  address: TpixDexService.nativeAddress,
  decimals: 18,
  native: true,
);
const _usdt = VerifiedToken(
  symbol: 'USDT',
  address: '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
  decimals: 6,
  native: false,
);

void main() {
  test('เพดานเท่าเว็บ: 10%', () {
    expect(kTpixMaxPriceImpactPct, 10);
  });

  group('amountOutFor — สูตร UniV2 (หัก 0.3% ก่อนคิด x·y=k)', () {
    test('ตรงกับ getAmountOut ของ router', () {
      // 1 USDT เข้าพูล 19.99/199.9 → (1e6·997·199.9e18)/(19.99e6·1000 + 1e6·997)
      final out = TpixDexService.amountOutFor(_wei('1000000'), _rUsdt, _rTpix);
      final expected = (_wei('1000000') * BigInt.from(997) * _rTpix) ~/
          (_rUsdt * BigInt.from(1000) + _wei('1000000') * BigInt.from(997));
      expect(out, expected);
      // ≈ 9.495 TPIX
      expect(TpixDexService.fromWei(out, 18), closeTo(9.4955, 0.001));
    });

    test('ค่าเพี้ยน/พูลว่าง = 0 ไม่ใช่ crash', () {
      expect(TpixDexService.amountOutFor(BigInt.zero, _rUsdt, _rTpix),
          BigInt.zero);
      expect(TpixDexService.amountOutFor(BigInt.one, BigInt.zero, _rTpix),
          BigInt.zero);
    });
  });

  group('priceImpactPct — สูตรเดียวกับเว็บ', () {
    test('ไม้เล็กมาก ≈ ค่าธรรมเนียมพูล 0.3%', () {
      final inWei = _wei('1000'); // 0.001 USDT
      final out = TpixDexService.amountOutFor(inWei, _rUsdt, _rTpix);
      final impact = TpixDexService.priceImpactPct(
          amountIn: inWei,
          amountOut: out,
          reserveIn: _rUsdt,
          reserveOut: _rTpix);
      expect(impact, closeTo(0.3, 0.01));
    });

    test('5 USDT เข้าพูล 19.99 USDT = เกินเพดานแน่นอน', () {
      final inWei = _wei('5000000');
      final out = TpixDexService.amountOutFor(inWei, _rUsdt, _rTpix);
      final impact = TpixDexService.priceImpactPct(
          amountIn: inWei,
          amountOut: out,
          reserveIn: _rUsdt,
          reserveOut: _rTpix);
      expect(impact, greaterThan(kTpixMaxPriceImpactPct));
    });
  });

  group('maxAmountInForImpact — จำนวนสูงสุดที่ยังไม่เกินเพดาน', () {
    for (final reserve in [_rUsdt, _rTpix, _wei('1000'), _wei('123456789012345678901234')]) {
      test('ผลลัพธ์ผ่านเพดานจริง และใกล้เพดาน (reserve $reserve)', () {
        final max = TpixDexService.maxAmountInForImpact(reserveIn: reserve);
        expect(max, greaterThan(BigInt.zero));
        final out = TpixDexService.amountOutFor(max, reserve, _rTpix);
        final impact = TpixDexService.priceImpactPct(
            amountIn: max,
            amountOut: out,
            reserveIn: reserve,
            reserveOut: _rTpix);
        expect(impact, lessThanOrEqualTo(kTpixMaxPriceImpactPct));
        // เผื่อไว้ 0.5% ของจำนวน ไม่ใช่เผื่อจนเหลือครึ่ง
        expect(impact, greaterThan(9.5));
      });
    }

    test('พูลจริง: จ่าย USDT ได้ราว 2.15 · ขาย TPIX ได้ราว 21.5 ต่อไม้', () {
      final maxUsdt = TpixDexService.fromWei(
          TpixDexService.maxAmountInForImpact(reserveIn: _rUsdt), 6);
      final maxTpix = TpixDexService.fromWei(
          TpixDexService.maxAmountInForImpact(reserveIn: _rTpix), 18);
      expect(maxUsdt, closeTo(2.15, 0.01));
      expect(maxTpix, closeTo(21.5, 0.1));
    });

    test('ไม่มี reserve / เพดาน 0 = 0', () {
      expect(TpixDexService.maxAmountInForImpact(reserveIn: BigInt.zero),
          BigInt.zero);
      expect(
          TpixDexService.maxAmountInForImpact(
              reserveIn: _rUsdt, limitPct: 0),
          BigInt.zero);
      // เพดานต่ำกว่าค่าธรรมเนียมพูล — ไม่มีไม้ไหนผ่านได้
      expect(
          TpixDexService.maxAmountInForImpact(
              reserveIn: _rUsdt, limitPct: 0.2),
          BigInt.zero);
    });
  });

  group('TpixDexQuote — ด่าน 10% และจำนวนแนะนำ', () {
    TpixDexQuote quote(BigInt amountIn, {bool buy = true}) {
      final rIn = buy ? _rUsdt : _rTpix;
      final rOut = buy ? _rTpix : _rUsdt;
      final out = TpixDexService.amountOutFor(amountIn, rIn, rOut);
      final from = buy ? _usdt : _tpix;
      final to = buy ? _tpix : _usdt;
      return TpixDexQuote(
        fromToken: from,
        toToken: to,
        amountIn: TpixDexService.fromWei(amountIn, from.decimals),
        netOutput: TpixDexService.fromWei(out, to.decimals),
        minReceived: 0,
        slippage: 0.5,
        priceImpact: TpixDexService.priceImpactPct(
            amountIn: amountIn,
            amountOut: out,
            reserveIn: rIn,
            reserveOut: rOut),
        amountInWei: amountIn,
        rawAmountOut: out,
        path: const [],
        router: '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04',
        reserveIn: rIn,
        reserveOut: rOut,
      );
    }

    test('ไม้ใหญ่โดนบล็อก + บอกจำนวนสูงสุดทั้งฝั่งจ่ายและฝั่งได้', () {
      final q = quote(_wei('10000000')); // 10 USDT
      expect(q.exceedsImpactLimit, isTrue);
      expect(q.maxAmountInUnderLimit, closeTo(2.15, 0.01)); // USDT
      expect(q.maxAmountOutUnderLimit, closeTo(19.4, 0.2)); // TPIX
    });

    test('ซื้อตามจำนวนสูงสุดที่แนะนำ → ผ่านเพดาน', () {
      final maxIn = TpixDexService.maxAmountInForImpact(reserveIn: _rUsdt);
      expect(quote(maxIn).exceedsImpactLimit, isFalse);
    });

    test('ขาย TPIX: จำนวนสูงสุดเป็นหน่วย TPIX (ช่องจำนวนในฟอร์ม)', () {
      final q = quote(_wei('100000000000000000000'), buy: false); // 100 TPIX
      expect(q.exceedsImpactLimit, isTrue);
      expect(q.maxAmountInUnderLimit, closeTo(21.5, 0.1));
    });

    test('ไม้ปกติไม่โดนบล็อก', () {
      expect(quote(_wei('500000')).exceedsImpactLimit, isFalse); // 0.5 USDT
    });

    test('อ่าน reserve ไม่ได้ = ไม่แนะนำตัวเลขมั่ว', () {
      final q = TpixDexQuote(
        fromToken: _usdt,
        toToken: _tpix,
        amountIn: 1,
        netOutput: 9,
        minReceived: 9,
        slippage: 0.5,
        priceImpact: 12,
        amountInWei: BigInt.one,
        rawAmountOut: BigInt.one,
        path: const [],
        router: '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04',
      );
      expect(q.exceedsImpactLimit, isTrue);
      expect(q.maxAmountInUnderLimit, isNull);
      expect(q.maxAmountOutUnderLimit, isNull);
    });
  });

  group('formatMaxAmount — ปัดลงเสมอ', () {
    test('ปัดลง ไม่ปัดขึ้น', () {
      expect(TpixDexService.formatMaxAmount(2.15099999), '2.1509');
      expect(TpixDexService.formatMaxAmount(21.49999), '21.4999');
      expect(TpixDexService.formatMaxAmount(0.1234567), '0.123456');
      expect(TpixDexService.formatMaxAmount(1500.129), '1500.12');
    });

    test('ตัดศูนย์ท้าย', () {
      expect(TpixDexService.formatMaxAmount(2.5), '2.5');
      expect(TpixDexService.formatMaxAmount(3), '3');
    });
  });
}
