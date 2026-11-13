/// TPIX TRADE — price impact ที่ "วัดไม่ได้" บนพูล TPIX DEX ต้องถูกบล็อก (L5)
///
/// สิ่งที่กันไว้ (กติกาเดียวกับ `isPriceImpactBlocked` ใน useTpixDex.js: ไม่รู้ค่า = บล็อก):
///   - อ่าน reserve ไม่ได้ และไม้จิ๋วก็วัดไม่ได้ → priceImpact ต้องเป็น null ไม่ใช่ 0
///     (เดิมกลายเป็น 0 แล้วผ่านด่าน 10% ทุกไม้ ไม่ว่าจะใหญ่แค่ไหน)
///   - quote ที่ไม่รู้ price impact = ส่งไม่ได้ ทั้งที่หน้าจอและที่ตัวส่ง
///
/// Developed by Xman Studio
library;

import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart'
    show SwapException, VerifiedToken;
import 'package:tpix_trade/services/tpix_dex_service.dart';

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

TpixDexQuote _quote(double? impact) => TpixDexQuote(
      fromToken: _usdt,
      toToken: _tpix,
      amountIn: 1,
      netOutput: 9,
      minReceived: 8.955,
      slippage: 0.5,
      priceImpact: impact,
      amountInWei: BigInt.from(1000000),
      rawAmountOut: BigInt.parse('9000000000000000000'),
      path: const [],
      router: '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04',
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('probeImpactPct — วัดด้วยไม้จิ๋วบนเส้นทางเดียวกัน', () {
    test('ไม้จิ๋วได้อัตราดีกว่าไม้จริง = ส่วนต่างคือ price impact', () {
      // ไม้จิ๋ว 1 → 10 (อัตรา 10) · ไม้จริง 1000 → 8000 (อัตรา 8) = 20%
      expect(
        TpixDexService.probeImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(8000),
          probeIn: BigInt.one,
          probeOut: BigInt.from(10),
        ),
        closeTo(20, 1e-9),
      );
    });

    test('อัตราเท่ากัน = 0 (วัดได้ และไม่ดันราคา)', () {
      expect(
        TpixDexService.probeImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(10000),
          probeIn: BigInt.one,
          probeOut: BigInt.from(10),
        ),
        0,
      );
    });

    test('วัดไม่ได้ = null ไม่ใช่ 0', () {
      // ไม้เล็กจนแบ่ง 1/1000 ไม่ได้
      expect(
        TpixDexService.probeImpactPct(
          amountIn: BigInt.from(500),
          amountOut: BigInt.from(4000),
          probeIn: BigInt.zero,
          probeOut: null,
        ),
        isNull,
      );
      // ไม้จิ๋ว revert / อ่านเชนไม่ได้
      expect(
        TpixDexService.probeImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(8000),
          probeIn: BigInt.one,
          probeOut: null,
        ),
        isNull,
      );
      expect(
        TpixDexService.probeImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(8000),
          probeIn: BigInt.one,
          probeOut: BigInt.zero,
        ),
        isNull,
      );
    });
  });

  group('TpixDexQuote — ไม่รู้ price impact = ส่งไม่ได้', () {
    test('null / NaN = impactUnknown และเกินเพดาน (fail-closed)', () {
      for (final impact in <double?>[null, double.nan, double.infinity]) {
        final q = _quote(impact);
        expect(q.impactUnknown, isTrue, reason: '$impact');
        expect(q.exceedsImpactLimit, isTrue, reason: '$impact');
      }
    });

    test('ค่าที่วัดได้ยังตัดสินด้วยเพดาน 10% เหมือนเดิม', () {
      expect(_quote(0).impactUnknown, isFalse);
      expect(_quote(0).exceedsImpactLimit, isFalse);
      expect(_quote(10).exceedsImpactLimit, isFalse);
      expect(_quote(10.01).exceedsImpactLimit, isTrue);
    });

    test('executeMarketSwap: ไม่รู้ price impact = โยนก่อนแตะกระเป๋า', () async {
      var sends = 0;
      await expectLater(
        TpixDexService().executeMarketSwap(
          quote: _quote(null),
          walletAddress: '0x1111111111111111111111111111111111111111',
          sendTx: ({required String to, Uint8List? data, BigInt? value}) async {
            sends++;
            return '0x${'ab' * 32}';
          },
        ),
        throwsA(same(kTpixImpactUnknown)),
      );
      expect(sends, 0);
    });

    test('executeMarketSwap: เกินเพดาน = โยนก่อนแตะกระเป๋า', () async {
      var sends = 0;
      await expectLater(
        TpixDexService().executeMarketSwap(
          quote: _quote(12),
          walletAddress: '0x1111111111111111111111111111111111111111',
          sendTx: ({required String to, Uint8List? data, BigInt? value}) async {
            sends++;
            return '0x${'ab' * 32}';
          },
        ),
        throwsA(isA<SwapException>()
            .having((e) => e.en, 'en', contains('12.0%'))),
      );
      expect(sends, 0);
    });

    test('minOutWei = ยอดจาก router หัก slippage (basis point)', () {
      // 9e18 × 0.995 = 8.955e18
      expect(_quote(0).minOutWei, BigInt.parse('8955000000000000000'));
    });
  });
}
