/// TPIX TRADE — ด่านของไม้บน BSC ก่อนเงินออกจากกระเป๋า
///
/// สิ่งที่กันไว้ (ต้องตรงกับเว็บ useSwap.js / Trade.vue):
///   - C1: `/swap/quote` ไม่ตอบ / ไม่สำเร็จ / ไม่มี fee_rate ที่เป็นตัวเลข = ไม่มีราคา (fail-closed)
///         ห้ามมีอัตราปริยาย 0.3% — 404 INVALID_CHAIN / INVALID_TOKEN / PAIR_DISABLED = คู่ถูกปิด
///   - C3: price impact วัดจากไม้จิ๋วบนเส้นทางเดียวกัน · เกิน 10% หรือวัดไม่ได้ = ไม่ส่ง
///   - L1: จำนวนตัดทศนิยมทิ้ง ไม่ปัดขึ้น · ตัดให้พอดียอดบนเชน · เกินจริง = ยอดไม่พอ
///   - L3: กระเป๋าค่าธรรมเนียมไม่พร้อม = ไม่ส่ง
///
/// ทุกเทสต์ใช้จุดเสียบของ service — ไม่ยิงเน็ต/เชนจริง
///
/// Developed by Xman Studio
library;

import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/models/bsc_trade_tokens.dart';
import 'package:tpix_trade/services/api_service.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart';
import 'package:tpix_trade/services/pending_swap_records.dart';
import 'package:web3dart/web3dart.dart';

const _usdtAddress = '0x55d398326f99059fF775485246999027B3197955';
const _wallet = '0x1111111111111111111111111111111111111111';
const _collector = '0x2222222222222222222222222222222222222222';
final _txHash = '0x${'ab' * 32}';
final _feeHash = '0x${'cd' * 32}';

const _usdt = VerifiedToken(
    symbol: 'USDT', address: _usdtAddress, decimals: 18, native: false);
const _bnb = VerifiedToken(
    symbol: 'BNB', address: kNativeTokenAddress, decimals: 18, native: true);

BigInt _e18(num v) => BscSwapService.toWei(v.toDouble(), 18);

SwapQuoteResponse _okTerms({Object? feeRate = 0.3, Object? slippage = 0.5}) =>
    SwapQuoteResponse(status: 200, quote: {
      'fee_rate': feeRate,
      'slippage': slippage,
    });

BscSwapQuote _quote({
  VerifiedToken from = _usdt,
  VerifiedToken to = _bnb,
  required BigInt gross,
  double feeRate = 0.3,
  double? priceImpact = 0.1,
  BigInt? rawOut,
}) {
  final split = BscSwapService.splitPlatformFee(gross, feeRate);
  final out = rawOut ?? _e18(1);
  return BscSwapQuote(
    fromToken: from,
    toToken: to,
    amountIn: BscSwapService.fromWei(gross, from.decimals),
    swapInput: BscSwapService.fromWei(split.swapWei, from.decimals),
    feeAmount: BscSwapService.fromWei(split.feeWei, from.decimals),
    feeRate: feeRate,
    slippage: 0.5,
    netOutput: BscSwapService.fromWei(out, to.decimals),
    minReceived: BscSwapService.fromWei(out, to.decimals) * 0.995,
    amountInSwapWei: split.swapWei,
    feeWei: split.feeWei,
    rawAmountOut: out,
    path: [
      EthereumAddress.fromHex(from.native ? kWbnbAddress : from.address),
      EthereumAddress.fromHex(to.native ? kWbnbAddress : to.address),
    ],
    priceImpact: priceImpact,
  );
}

void main() {
  // กันพลาด: ถ้ามีโค้ดไหนหลุดไปยิง HTTP จริง binding ของเทสต์ตอบ 400 แทน ไม่ออกเน็ต
  TestWidgetsFlutterBinding.ensureInitialized();

  final service = BscSwapService();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    service.debugReset();
    service.debugSeedVerified(_usdt);
  });
  tearDown(service.debugReset);

  group('C1 — อัตราค่าธรรมเนียมจาก backend แบบ fail-closed', () {
    test('ยิงไม่ถึงเซิร์ฟเวอร์ / 500 = ตรวจค่าธรรมเนียมไม่สำเร็จ (ไม่มีอัตราปริยาย)', () {
      for (final res in const [
        SwapQuoteResponse(),
        SwapQuoteResponse(status: 500, errorCode: 'QUOTE_ERROR'),
        SwapQuoteResponse(status: 200),
      ]) {
        expect(() => BscSwapService.termsFromBackend(res),
            throwsA(same(kSwapFeeConfigUnavailable)));
      }
    });

    test('404 หรือรหัสปิดคู่ = คู่นี้ปิดการเทรด', () {
      for (final res in const [
        SwapQuoteResponse(status: 404),
        SwapQuoteResponse(status: 404, errorCode: 'INVALID_CHAIN'),
        SwapQuoteResponse(status: 404, errorCode: 'INVALID_TOKEN'),
        SwapQuoteResponse(status: 404, errorCode: 'PAIR_DISABLED'),
        SwapQuoteResponse(status: 400, errorCode: 'PAIR_DISABLED'),
      ]) {
        expect(() => BscSwapService.termsFromBackend(res),
            throwsA(same(kSwapPairDisabled)));
      }
    });

    test('fee_rate ไม่ใช่ตัวเลข / ติดลบ / ไม่มี = ไม่มีราคา', () {
      for (final rate in <Object?>[null, 'abc', true, -0.1, double.nan, 100]) {
        expect(
          () => BscSwapService.termsFromBackend(_okTerms(feeRate: rate)),
          throwsA(same(kSwapFeeConfigUnavailable)),
          reason: 'fee_rate=$rate',
        );
      }
    });

    test('fee_rate เป็นเลขหรือข้อความตัวเลข = ใช้ค่านั้น (รวม 0)', () {
      expect(BscSwapService.termsFromBackend(_okTerms()).feeRate, 0.3);
      expect(
          BscSwapService.termsFromBackend(_okTerms(feeRate: '0.25')).feeRate,
          0.25);
      expect(BscSwapService.termsFromBackend(_okTerms(feeRate: 0)).feeRate, 0);
    });

    test('slippage: ที่ผู้ใช้เลือกชนะ → backend → 0.5 · เกิน 50% ถูกปฏิเสธ', () {
      expect(
          BscSwapService.termsFromBackend(_okTerms(slippage: 1.0),
                  slippageOverride: 3)
              .slippage,
          3);
      expect(
          BscSwapService.termsFromBackend(_okTerms(slippage: 1.0)).slippage, 1);
      expect(
          BscSwapService.termsFromBackend(_okTerms(slippage: null)).slippage,
          0.5);
      expect(
        () => BscSwapService.termsFromBackend(_okTerms(), slippageOverride: 99),
        throwsA(isA<SwapException>()),
      );
    });

    test('parseSwapQuote: อ่านรหัส error และชั้น data.quote', () {
      final disabled = ApiService.parseSwapQuote(404, {
        'success': false,
        'error': {'code': 'PAIR_DISABLED', 'message': 'x'},
      });
      expect(disabled.ok, isFalse);
      expect(disabled.status, 404);
      expect(disabled.errorCode, 'PAIR_DISABLED');

      final ok = ApiService.parseSwapQuote(200, {
        'success': true,
        'data': {
          'quote': {'fee_rate': 0.3, 'slippage': 0.5},
        },
      });
      expect(ok.ok, isTrue);
      expect(ok.quote!['fee_rate'], 0.3);

      // หน้า HTML ของ CDN / success:false ที่ตอบ 200 = ไม่สำเร็จ
      expect(ApiService.parseSwapQuote(200, '<html>').ok, isFalse);
      expect(ApiService.parseSwapQuote(200, {'success': false}).ok, isFalse);
      expect(ApiService.parseSwapQuote(null, null).ok, isFalse);
    });

    test('getQuote: backend ล้ม = โยน และไม่ถามราคา router เลย', () async {
      var routerCalls = 0;
      service.debugAmountsOut = (amountIn, path) async {
        routerCalls++;
        return _e18(1);
      };
      service.debugQuoteFetcher = ({
        required fromToken,
        required toToken,
        required amount,
        required chainId,
      }) async =>
          const SwapQuoteResponse(status: 404, errorCode: 'PAIR_DISABLED');

      await expectLater(
        service.getQuote(fromSymbol: 'USDT', toSymbol: 'BNB', amount: 100),
        throwsA(same(kSwapPairDisabled)),
      );
      expect(routerCalls, 0);
    });

    test('getQuote: ตัวขอ quote โยน exception = ตรวจค่าธรรมเนียมไม่สำเร็จ', () async {
      service.debugAmountsOut = (amountIn, path) async => _e18(1);
      service.debugQuoteFetcher = ({
        required fromToken,
        required toToken,
        required amount,
        required chainId,
      }) async =>
          throw StateError('boom');

      await expectLater(
        service.getQuote(fromSymbol: 'USDT', toSymbol: 'BNB', amount: 100),
        throwsA(same(kSwapFeeConfigUnavailable)),
      );
    });

    test('getQuote: สำเร็จ = ใช้อัตราจาก backend แบ่งค่าธรรมเนียมด้วย BigInt', () async {
      String? sentAmount;
      service.debugQuoteFetcher = ({
        required fromToken,
        required toToken,
        required amount,
        required chainId,
      }) async {
        sentAmount = amount;
        expect(fromToken, _usdtAddress);
        expect(chainId, 56);
        return _okTerms(feeRate: 0.5);
      };
      // ไม้จริง: 0.2 BNB · ไม้จิ๋ว (1/1000): อัตราเดียวกัน = ไม่ดันราคา
      service.debugAmountsOut = (amountIn, path) async =>
          amountIn * BigInt.from(2) ~/ BigInt.from(1000);

      final q = await service.getQuote(
        fromSymbol: 'USDT',
        toSymbol: 'BNB',
        amount: 0,
        amountText: '100.5',
        slippageOverride: 1,
      );
      expect(sentAmount, '100.5');
      expect(q.feeRate, 0.5);
      expect(q.slippage, 1);
      expect(q.grossWei, BigInt.parse('100500000000000000000'));
      // fee 0.5% ของ 100.5 = 0.5025 พอดี · swap + fee = ยอดที่จ่ายเป๊ะ
      expect(q.feeWei, BigInt.parse('502500000000000000'));
      expect(q.amountInSwapWei + q.feeWei, q.grossWei);
      expect(q.priceImpact, 0);
    });
  });

  group('C3 — price impact (สูตรเดียวกับเว็บ)', () {
    test('เพดานเท่าเว็บ: 10%', () {
      expect(kBscMaxPriceImpactPct, 10);
    });

    test('priceImpactPct: เทียบอัตราไม้จริงกับไม้จิ๋ว', () {
      // ไม้จิ๋ว 1 → 2 (อัตรา 2.0) · ไม้จริง 1000 → 1800 (อัตรา 1.8) = ดันราคา 10%
      expect(
        BscSwapService.priceImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(1800),
          refIn: BigInt.from(1),
          refOut: BigInt.from(2),
        ),
        10,
      );
      // อัตราเท่ากัน / ดีกว่า = 0
      expect(
        BscSwapService.priceImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(2001),
          refIn: BigInt.from(1),
          refOut: BigInt.from(2),
        ),
        0,
      );
    });

    test('priceImpactPct: ข้อมูลไม่ครบ = null (ไม่ใช่ 0)', () {
      expect(
        BscSwapService.priceImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(1800),
          refIn: BigInt.zero,
          refOut: BigInt.from(2),
        ),
        isNull,
      );
      expect(
        BscSwapService.priceImpactPct(
          amountIn: BigInt.from(1000),
          amountOut: BigInt.from(1800),
          refIn: BigInt.one,
          refOut: BigInt.zero,
        ),
        isNull,
      );
    });

    test('isPriceImpactBlocked: เกิน 10% บล็อกเสมอ · ไม่รู้ = บล็อก เว้นแต่มีราคาตลาดเทียบ', () {
      expect(BscSwapService.isPriceImpactBlocked(10), isFalse);
      expect(BscSwapService.isPriceImpactBlocked(10.01), isTrue);
      expect(
          BscSwapService.isPriceImpactBlocked(10.01, hasMarketReference: true),
          isTrue);
      expect(BscSwapService.isPriceImpactBlocked(null), isTrue);
      expect(BscSwapService.isPriceImpactBlocked(double.nan), isTrue);
      expect(BscSwapService.isPriceImpactBlocked(null, hasMarketReference: true),
          isFalse);
    });

    test('getQuote: ไม้จิ๋วอ่านไม่ได้ = priceImpact null (ไม่รู้)', () async {
      service.debugQuoteFetcher = ({
        required fromToken,
        required toToken,
        required amount,
        required chainId,
      }) async =>
          _okTerms();
      var call = 0;
      service.debugAmountsOut = (amountIn, path) async {
        call++;
        return call == 1 ? _e18(1) : null; // ไม้จริงได้ราคา · ไม้จิ๋วล้ม
      };
      final q = await service.getQuote(
          fromSymbol: 'USDT', toSymbol: 'BNB', amount: 100);
      expect(q.priceImpact, isNull);
      expect(q.isImpactBlocked(), isTrue);
      expect(q.isImpactBlocked(hasMarketReference: true), isFalse);
    });

    test('getQuote: ไม้ใหญ่ดันราคา = priceImpact ตามจริง', () async {
      service.debugQuoteFetcher = ({
        required fromToken,
        required toToken,
        required amount,
        required chainId,
      }) async =>
          _okTerms(feeRate: 0);
      var call = 0;
      service.debugAmountsOut = (amountIn, path) async {
        call++;
        // ไม้จริง: อัตรา 0.0015 · ไม้จิ๋ว: อัตรา 0.002 → ดันราคา 25%
        return call == 1
            ? amountIn * BigInt.from(15) ~/ BigInt.from(10000)
            : amountIn * BigInt.from(2) ~/ BigInt.from(1000);
      };
      final q = await service.getQuote(
          fromSymbol: 'USDT', toSymbol: 'BNB', amount: 1000);
      expect(q.priceImpact, 25);
      expect(q.isImpactBlocked(hasMarketReference: true), isTrue);
    });

    test('executeMarketSwap: เกินเพดาน / วัดไม่ได้ = โยนก่อนแตะกระเป๋า', () async {
      var sends = 0;
      Future<String?> sendTx(
          {required String to, Uint8List? data, BigInt? value}) async {
        sends++;
        return _txHash;
      }

      await expectLater(
        service.executeMarketSwap(
          quote: _quote(gross: _e18(100), priceImpact: 12.5),
          walletAddress: _wallet,
          sendTx: sendTx,
          allowUnknownImpact: true,
        ),
        throwsA(isA<SwapException>()
            .having((e) => e.en, 'en', contains('12.50%'))),
      );
      await expectLater(
        service.executeMarketSwap(
          quote: _quote(gross: _e18(100), priceImpact: null),
          walletAddress: _wallet,
          sendTx: sendTx,
        ),
        throwsA(same(kSwapImpactUnknown)),
      );
      expect(sends, 0);
    });
  });

  group('L1 — ตัดทศนิยมทิ้ง ไม่ปัดขึ้น + ตัดให้พอดียอดบนเชน', () {
    test('toWei ไม่ปัดขึ้น', () {
      // 1.9999999999996 ที่ 12 ตำแหน่ง toStringAsFixed ให้ "2.000000000000"
      final wei = BscSwapService.toWei(1.9999999999996, 18);
      expect(wei < BigInt.parse('2000000000000000000'), isTrue);
      expect(wei, BigInt.parse('1999999999999000000'));
      // ค่าปกติไม่เปลี่ยน
      expect(BscSwapService.toWei(1.5, 18),
          BigInt.parse('1500000000000000000'));
      expect(BscSwapService.toWei(0.123456, 6), BigInt.from(123456));
      // ค่าที่ใช้ไม่ได้ = 0
      expect(BscSwapService.toWei(double.nan, 18), BigInt.zero);
      expect(BscSwapService.toWei(-1, 18), BigInt.zero);
      expect(BscSwapService.toWei(double.infinity, 18), BigInt.zero);
    });

    test('parseUnits: ตรงตัวจากข้อความ ทศนิยมเกินถูกตัดทิ้ง', () {
      expect(BscSwapService.parseUnits('1.23456789', 6), BigInt.from(1234567));
      expect(BscSwapService.parseUnits('0.000001', 6), BigInt.one);
      expect(BscSwapService.parseUnits('0.0000019', 6), BigInt.one);
      expect(BscSwapService.parseUnits('abc', 6), isNull);
      expect(BscSwapService.parseUnits('', 6), isNull);
      expect(BscSwapService.parseUnits('1e5', 6), isNull);
    });

    test('floorDecimal: ปุ่ม % ต้องไม่เกินยอดจริง', () {
      // toStringAsFixed(4) ของ 1.23456 = "1.2346" (เกินยอด) — ต้องได้ 1.2345
      expect(BscSwapService.floorDecimal(1.23456, 4), '1.2345');
      expect(BscSwapService.floorDecimal(0.99999999, 4), '0.9999');
      expect(BscSwapService.floorDecimal(2, 8), '2');
      expect(BscSwapService.floorDecimal(0.5, 8), '0.5');
      expect(BscSwapService.floorDecimal(0, 8), '0');
      expect(BscSwapService.floorDecimal(double.nan, 8), '0');
      for (final v in [0.1 + 0.2, 123.456789012345, 0.000000019, 99999.99999999]) {
        final text = BscSwapService.floorDecimal(v, 8);
        expect(double.parse(text) <= v, isTrue, reason: '$v → $text');
      }
    });

    test('splitPlatformFee: ผลรวมเท่ายอดที่จ่ายเสมอ ค่าธรรมเนียมปัดขึ้น 1 หน่วย', () {
      final gross = BigInt.from(1000001);
      final split = BscSwapService.splitPlatformFee(gross, 0.3);
      expect(split.feeWei + split.swapWei, gross);
      expect(split.feeWei, BigInt.from(3001)); // 3000.003 → 3001
      final none = BscSwapService.splitPlatformFee(gross, 0);
      expect(none.feeWei, BigInt.zero);
      expect(none.swapWei, gross);
    });

    test('clampToBalance: ยอดพอ / อ่านยอดไม่ได้ = quote เดิม', () {
      final q = _quote(gross: _e18(100));
      expect(BscSwapService.clampToBalance(q, _e18(100)), same(q));
      expect(BscSwapService.clampToBalance(q, _e18(500)), same(q));
      expect(BscSwapService.clampToBalance(q, null), same(q));
    });

    test('clampToBalance: เกินด้วยเศษ (≤ 0.01%) = ตัดให้พอดียอด', () {
      final gross = _e18(100);
      final balance = gross - BigInt.from(12345); // ขาดไปนิดเดียว
      final q = _quote(gross: gross, rawOut: _e18(2));
      final c = BscSwapService.clampToBalance(q, balance);
      expect(c.grossWei, balance);
      expect(c.amountInSwapWei + c.feeWei, balance);
      expect(c.feeWei > BigInt.zero, isTrue);
      expect(c.rawAmountOut < q.rawAmountOut, isTrue);
      expect(c.rawAmountOut,
          q.rawAmountOut * c.amountInSwapWei ~/ q.amountInSwapWei);
    });

    test('clampToBalance: เกินจริง = ยอดไม่พอ', () {
      final q = _quote(gross: _e18(100));
      expect(() => BscSwapService.clampToBalance(q, _e18(99)),
          throwsA(same(kSwapInsufficientBalance)));
    });

    test('clampToBalance: BNB เกินแม้เศษ = ยอดไม่พอ (ต้องเหลือไว้จ่ายค่าแก๊ส)', () {
      final gross = _e18(1);
      final q = _quote(from: _bnb, to: _usdt, gross: gross);
      expect(() => BscSwapService.clampToBalance(q, gross - BigInt.one),
          throwsA(same(kSwapInsufficientBalance)));
    });

    test('executeMarketSwap: ยอดบนเชนไม่พอ = โยนก่อนแตะกระเป๋า', () async {
      var sends = 0;
      service.debugFeeInfoFetcher =
          () async => {'fee_collector': _collector};
      service.debugBalanceReader = (token, wallet) async => _e18(50);
      await expectLater(
        service.executeMarketSwap(
          quote: _quote(gross: _e18(100)),
          walletAddress: _wallet,
          sendTx: ({required String to, Uint8List? data, BigInt? value}) async {
            sends++;
            return _txHash;
          },
        ),
        throwsA(same(kSwapInsufficientBalance)),
      );
      expect(sends, 0);
    });
  });

  group('L3 — กระเป๋าค่าธรรมเนียม (fail-closed)', () {
    test('ensureFeeCollector: ไม่ตอบ / ที่อยู่ผิดรูป = โยน', () async {
      service.debugFeeInfoFetcher = () async => null;
      await expectLater(
          service.ensureFeeCollector(), throwsA(isA<SwapException>()));
      service.debugFeeInfoFetcher = () async => {'fee_collector': '0x123'};
      await expectLater(
          service.ensureFeeCollector(), throwsA(isA<SwapException>()));
      service.debugFeeInfoFetcher = () async => throw StateError('x');
      await expectLater(
          service.ensureFeeCollector(), throwsA(isA<SwapException>()));
    });

    test('ensureFeeCollector: จำค่าไว้ ถามเซิร์ฟเวอร์ครั้งเดียว', () async {
      var calls = 0;
      service.debugFeeInfoFetcher = () async {
        calls++;
        return {'fee_collector': _collector};
      };
      expect(await service.ensureFeeCollector(), _collector);
      expect(await service.ensureFeeCollector(), _collector);
      expect(calls, 1);
    });

    test('executeMarketSwap: ไม่รู้กระเป๋าค่าธรรมเนียม = ไม่ส่ง', () async {
      var sends = 0;
      service.debugFeeInfoFetcher = () async => null;
      await expectLater(
        service.executeMarketSwap(
          quote: _quote(gross: _e18(100)),
          walletAddress: _wallet,
          sendTx: ({required String to, Uint8List? data, BigInt? value}) async {
            sends++;
            return _txHash;
          },
        ),
        throwsA(isA<SwapException>()),
      );
      expect(sends, 0);
    });
  });

  group('executeMarketSwap — บันทึกไม้เข้าคิว (C4) + ข้อมูลประกอบ (L8)', () {
    late List<Map<String, dynamic>> posted;
    late SwapRecordOutcome outcome;
    late PendingSwapRecords queue;

    setUp(() {
      posted = [];
      outcome = SwapRecordOutcome.recorded;
      queue = PendingSwapRecords.forTest(poster: (payload) async {
        posted.add(payload);
        return outcome;
      });
      service.debugQueue = queue;
      service.debugFeeInfoFetcher =
          () async => {'fee_collector': _collector};
      service.debugBalanceReader = (token, wallet) async => _e18(1000);
      service.debugReceiptStatus = (hash) async => true;
    });

    Future<SwapResult> run({BigInt? minOutFloor}) {
      final sent = <String>[];
      return service.executeMarketSwap(
        quote: _quote(gross: _e18(300), rawOut: _e18(1)),
        walletAddress: _wallet,
        meta: const SwapRecordMeta(pair: 'BNB-USDT', side: 'buy', price: 301),
        minOutFloor: minOutFloor,
        sendTx: ({required String to, Uint8List? data, BigInt? value}) async {
          sent.add(to);
          // ใบแรก = สวอปเข้า router · ใบที่สอง = โอนค่าธรรมเนียม (transfer ที่สัญญาเหรียญ)
          return sent.length == 1 ? _txHash : _feeHash;
        },
      );
    }

    test('สำเร็จ: แจ้งบันทึกพร้อม hash ค่าธรรมเนียม + คู่/ฝั่ง/ราคา แล้วออกจากคิว', () async {
      final result = await run();
      expect(result.confirmed, isTrue);
      expect(result.feeCollected, isTrue);
      expect(result.recorded, isTrue);
      expect(posted, hasLength(1));
      final p = posted.single;
      expect(p['tx_hash'], _txHash);
      expect(p['fee_tx_hash'], _feeHash);
      expect(p['fee_collected'], isTrue);
      expect(p['chain_id'], 56);
      expect(p['wallet_address'], _wallet);
      expect(p['pair'], 'BNB/USDT');
      expect(p['side'], 'buy');
      // ราคาที่ได้จริง = จ่าย 300 USDT ได้ 1 BNB
      expect(p['price'], closeTo(300, 1e-9));
      expect(p.keys.any((k) => k.startsWith('_')), isFalse);
      expect(await queue.all(), isEmpty);
    });

    test('แจ้งไม่ถึงเซิร์ฟเวอร์: ไม้ยังสำเร็จ แต่บันทึกค้างอยู่ในคิว (ส่งใหม่ได้)', () async {
      outcome = SwapRecordOutcome.offline;
      final result = await run();
      expect(result.confirmed, isTrue);
      expect(result.recorded, isFalse);
      final pending = await queue.all();
      expect(pending, hasLength(1));
      expect(pending.single['tx_hash'], _txHash);
      expect(pending.single['fee_tx_hash'], _feeHash);

      // ส่งใหม่ตอนโหลดพอร์ต — ผ่านแล้วออกจากคิว
      outcome = SwapRecordOutcome.recorded;
      expect(await queue.flush(_wallet), 1);
      expect(await queue.all(), isEmpty);
    });

    test('ไม้ล้มบนเชน: ไม่มีอะไรให้บันทึก — ออกจากคิว ไม่แจ้งเซิร์ฟเวอร์', () async {
      service.debugReceiptStatus = (hash) async => false;
      await expectLater(run(), throwsA(isA<SwapException>()));
      expect(posted, isEmpty);
      expect(await queue.all(), isEmpty);
    });

    test('ยังไม่ยืนยันในเวลาที่รอ: ไม่โอนค่าธรรมเนียม แต่บันทึกถูกเก็บ/แจ้งไว้', () async {
      service.debugReceiptStatus = (hash) async => null;
      outcome = SwapRecordOutcome.notYetOnChain;
      final result = await run();
      expect(result.confirmed, isFalse);
      expect(result.feeCollected, isFalse);
      expect(result.recorded, isFalse);
      expect(posted.single['fee_collected'], isFalse);
      expect(posted.single.containsKey('fee_tx_hash'), isFalse);
      expect(await queue.all(), hasLength(1));
    });
  });
}
