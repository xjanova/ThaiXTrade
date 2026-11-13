/// TPIX TRADE — ค่าวางไม้ด้วยเครดิต TPIX ของไม้บน TPIX Chain (C9)
///
/// สิ่งที่กันไว้ (กติกาเดียวกับ useTradingFee.js `isCreditOnly` / `sendWithTicket`):
///   - "จ่ายด้วยเครดิตเท่านั้น" = enabled && !waived && onchain.available == false
///   - ถามใบเสนอราคาไม่ได้ = ไม่ส่งไม้ (fail-closed)
///   - เครดิตไม่พอ (can_place false) = ไม่ส่งไม้ บอกเหตุผลของเซิร์ฟเวอร์ + ให้ไปเติมเครดิต
///   - ไม่มีอะไรออกจากกระเป๋า → คืนค่าบริการ · ธุรกรรมออกไปแล้ว → ผูกไว้ ห้ามคืนเอง
///   - ไม้ยืนยันแล้ว → ปิดใบ · ยังไม่ยืนยัน → ผูกไว้เฉย ๆ ให้เซิร์ฟเวอร์ตัดสินจากเชน
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart' show SwapException;
import 'package:tpix_trade/services/trading_fee_service.dart';

const _wallet = '0x1111111111111111111111111111111111111111';
final _tx = '0x${'ab' * 32}';

TradingFeeQuote _q({
  bool enabled = true,
  bool waived = false,
  bool onchain = false,
  bool canPlace = true,
  String? reason,
}) =>
    TradingFeeQuote(
      enabled: enabled,
      waived: waived,
      onchainAvailable: onchain,
      canPlace: canPlace,
      reason: reason,
    );

/// ตัวปลอมของเซิร์ฟเวอร์ — จดทุกคำขอไว้ตามลำดับ
class _FakeClient implements TradingFeeClient {
  TradingFeeQuote? quoteResult = _q();
  bool quoteThrows = false;
  TicketIssue issueResult = const TicketIssue(uuid: 'ticket-1');
  List<bool> bindResults = [true];
  final List<String> calls = [];
  double? quotedValue;
  Map<String, Object?>? issued;

  @override
  Future<TradingFeeQuote?> quote({
    required String wallet,
    required double orderValueUsd,
    required int chainId,
    String? pair,
  }) async {
    calls.add('quote');
    quotedValue = orderValueUsd;
    if (quoteThrows) throw StateError('boom');
    return quoteResult;
  }

  @override
  Future<TicketIssue> issueTicket({
    required String wallet,
    required String pair,
    required String side,
    required double orderValueUsd,
    required int chainId,
  }) async {
    calls.add('issue');
    issued = {
      'wallet': wallet,
      'pair': pair,
      'side': side,
      'value': orderValueUsd,
      'chain': chainId,
    };
    return issueResult;
  }

  @override
  Future<bool> bindTicket({
    required String wallet,
    required String uuid,
    required String orderTxHash,
  }) async {
    calls.add('bind:$orderTxHash');
    return bindResults.length > 1 ? bindResults.removeAt(0) : bindResults.first;
  }

  @override
  Future<bool> consumeTicket({
    required String wallet,
    required String uuid,
    required String orderTxHash,
  }) async {
    calls.add('consume:$orderTxHash');
    return true;
  }

  @override
  Future<bool> refundTicket({
    required String wallet,
    required String uuid,
    required String reason,
  }) async {
    calls.add('refund');
    return true;
  }
}

Future<TradingFeePlan> _plan(_FakeClient client, {double? value = 25}) =>
    TradingFeeFlow.plan(
      wallet: _wallet,
      pair: 'TPIX/USDT',
      side: 'buy',
      orderValueUsd: value,
      chainId: 4289,
      client: client,
    );

void main() {
  group('TradingFeeQuote — อ่านใบเสนอราคา', () {
    test('TPIX Chain: จ่ายบนเชนไม่ได้ = จ่ายด้วยเครดิตเท่านั้น', () {
      final q = TradingFeeQuote.fromJson({
        'enabled': true,
        'waived': false,
        'onchain': {'available': false, 'reason': 'x'},
        'tpix': {'available': true, 'fee_tpix': 1.5, 'shortfall': 0.5},
        'can_place': false,
        'reason': 'เครดิตไม่พอ',
      });
      expect(q.creditOnly, isTrue);
      expect(q.canPlace, isFalse);
      expect(q.reason, 'เครดิตไม่พอ');
      expect(q.feeTpix, 1.5);
      expect(q.shortfall, 0.5);
    });

    test('ระบบปิด / ได้รับยกเว้น / จ่ายบนเชนได้ = ไม่ใช่เครดิตเท่านั้น', () {
      expect(_q(enabled: false).creditOnly, isFalse);
      expect(_q(waived: true).creditOnly, isFalse);
      expect(_q(onchain: true).creditOnly, isFalse);
      // ไม่มีช่อง onchain = ถือว่าจ่ายบนเชนได้ (ตรงกับ `=== false` ของเว็บ)
      expect(
          TradingFeeQuote.fromJson({'enabled': true, 'can_place': true})
              .creditOnly,
          isFalse);
    });

    test('ไม่มี can_place = ถือว่าวางไม่ได้ (fail-closed)', () {
      final q = TradingFeeQuote.fromJson({
        'enabled': true,
        'onchain': {'available': false},
      });
      expect(q.creditOnly, isTrue);
      expect(q.canPlace, isFalse);
    });
  });

  group('needsTicket — ตัดสินทางจ่าย', () {
    test('ถามใบเสนอราคาไม่ได้ = ไม่ส่งไม้', () {
      expect(
        () => TradingFeeFlow.needsTicket(null, hasOrderValue: true),
        throwsA(isA<TradingFeeException>()
            .having((e) => e.block, 'block', TradingFeeBlock.quoteFailed)),
      );
    });

    test('ไม่ใช่เครดิตเท่านั้น = ส่งตามเดิม ไม่มีใบ (แม้ไม่รู้มูลค่าไม้)', () {
      for (final q in [_q(enabled: false), _q(waived: true), _q(onchain: true)]) {
        expect(TradingFeeFlow.needsTicket(q, hasOrderValue: true), isFalse);
        expect(TradingFeeFlow.needsTicket(q, hasOrderValue: false), isFalse);
      }
    });

    test('เครดิตเท่านั้น + วางได้ = ต้องขอใบ', () {
      expect(TradingFeeFlow.needsTicket(_q(), hasOrderValue: true), isTrue);
    });

    test('เครดิตเท่านั้น + เครดิตไม่พอ = ไม่ส่ง พร้อมเหตุผลของเซิร์ฟเวอร์และทางออก', () {
      try {
        TradingFeeFlow.needsTicket(
            _q(canPlace: false, reason: 'เครดิต TPIX ไม่พอ'),
            hasOrderValue: true);
        fail('ต้องโยน');
      } on TradingFeeException catch (e) {
        expect(e.block, TradingFeeBlock.creditRequired);
        expect(e.needsCredit, isTrue);
        expect(e.th, contains('เครดิต TPIX ไม่พอ'));
        expect(e.th, contains('tpix.online'));
        expect(e.en, contains('tpix.online'));
      }
    });

    test('เครดิตไม่พอแต่เซิร์ฟเวอร์ไม่ให้เหตุผล = ข้อความสำรองสองภาษา', () {
      final e = TradingFeeException.creditRequired(null);
      expect(e.en, contains('Not enough TPIX credit'));
      expect(e.th, contains('เครดิต TPIX ไม่พอ'));
    });

    test('เครดิตเท่านั้น แต่ไม้ไม่มีฝั่ง stablecoin = ไม่ส่ง (ไม่เดามูลค่า)', () {
      expect(
        () => TradingFeeFlow.needsTicket(_q(), hasOrderValue: false),
        throwsA(isA<TradingFeeException>()
            .having((e) => e.block, 'block', TradingFeeBlock.noStableLeg)),
      );
    });
  });

  group('orderValueUsd — ฝั่ง stablecoin ของไม้', () {
    test('ซื้อ = ยอดที่จ่าย · ขาย = ยอดที่จะได้ · ไม่มีฝั่ง stablecoin = null', () {
      expect(
          TradingFeeFlow.orderValueUsd(
              fromIsStable: true, toIsStable: false, amountIn: 25, netOutput: 240),
          25);
      expect(
          TradingFeeFlow.orderValueUsd(
              fromIsStable: false, toIsStable: true, amountIn: 240, netOutput: 24.5),
          24.5);
      expect(
          TradingFeeFlow.orderValueUsd(
              fromIsStable: false, toIsStable: false, amountIn: 1, netOutput: 2),
          isNull);
      expect(
          TradingFeeFlow.orderValueUsd(
              fromIsStable: true, toIsStable: false, amountIn: 0, netOutput: 2),
          isNull);
    });
  });

  group('plan — ถามใบเสนอราคาสด', () {
    test('ตัวถามโยน exception = ไม่ส่งไม้', () async {
      final client = _FakeClient()..quoteThrows = true;
      await expectLater(
        _plan(client),
        throwsA(isA<TradingFeeException>()
            .having((e) => e.block, 'block', TradingFeeBlock.quoteFailed)),
      );
    });

    test('ถามไม่สำเร็จ (null) = ไม่ส่งไม้', () async {
      final client = _FakeClient()..quoteResult = null;
      await expectLater(_plan(client), throwsA(isA<TradingFeeException>()));
    });

    test('ไม่มีฝั่ง stablecoin: ยังถามเซิร์ฟเวอร์ (มูลค่า 1) — ระบบปิด = ส่งได้ตามเดิม', () async {
      final client = _FakeClient()..quoteResult = _q(enabled: false);
      final plan = await _plan(client, value: null);
      expect(client.quotedValue, 1);
      expect(plan.needsTicket, isFalse);
    });

    test('ไม่มีฝั่ง stablecoin + เครดิตเท่านั้น = บล็อก', () async {
      final client = _FakeClient();
      await expectLater(
        _plan(client, value: null),
        throwsA(isA<TradingFeeException>()
            .having((e) => e.block, 'block', TradingFeeBlock.noStableLeg)),
      );
    });
  });

  group('send — ใบอนุญาตกับธุรกรรม', () {
    test('ไม่ต้องมีใบ: ส่งตรง ๆ ไม่แตะเซิร์ฟเวอร์ค่าวางไม้อีก', () async {
      final client = _FakeClient()..quoteResult = _q(onchain: true);
      final plan = await _plan(client);
      final result = await TradingFeeFlow.send<String>(
        plan: plan,
        client: client,
        send: (onSent) async {
          onSent(_tx);
          return 'done';
        },
      );
      expect(result, 'done');
      expect(client.calls, ['quote']);
    });

    test('สำเร็จ: ขอใบ → ผูกกับ tx → ปิดใบ', () async {
      final client = _FakeClient();
      final plan = await _plan(client);
      final result = await TradingFeeFlow.send<bool>(
        plan: plan,
        client: client,
        isConfirmed: (r) => r,
        send: (onSent) async {
          expect(client.calls, ['quote', 'issue']);
          onSent(_tx);
          return true;
        },
      );
      expect(result, isTrue);
      expect(client.calls, ['quote', 'issue', 'bind:$_tx', 'consume:$_tx']);
      expect(client.issued, {
        'wallet': _wallet,
        'pair': 'TPIX/USDT',
        'side': 'buy',
        'value': 25.0,
        'chain': 4289,
      });
    });

    test('ส่งออกไปแล้วแต่ยังไม่ยืนยัน: ผูกไว้ ไม่ปิดใบ ไม่คืน', () async {
      final client = _FakeClient();
      final plan = await _plan(client);
      await TradingFeeFlow.send<bool>(
        plan: plan,
        client: client,
        isConfirmed: (r) => r,
        send: (onSent) async {
          onSent(_tx);
          return false; // ยังไม่ยืนยัน
        },
      );
      expect(client.calls, ['quote', 'issue', 'bind:$_tx']);
    });

    test('ผู้ใช้ปฏิเสธในกระเป๋า (ไม่มีอะไรออก): คืนค่าบริการ แล้วโยน error เดิมต่อ', () async {
      final client = _FakeClient();
      final plan = await _plan(client);
      const rejected = SwapException('rejected', 'ปฏิเสธ');
      await expectLater(
        TradingFeeFlow.send<bool>(
          plan: plan,
          client: client,
          send: (onSent) async => throw rejected,
        ),
        throwsA(same(rejected)),
      );
      expect(client.calls, ['quote', 'issue', 'refund']);
    });

    test('ธุรกรรมออกไปแล้วแล้วล้ม/ไม่รู้ผล: ผูกไว้อย่างเดียว ห้ามคืน', () async {
      final client = _FakeClient();
      final plan = await _plan(client);
      const failed = SwapException('failed on-chain', 'ล้มบนเชน');
      await expectLater(
        TradingFeeFlow.send<bool>(
          plan: plan,
          client: client,
          send: (onSent) async {
            onSent(_tx);
            throw failed;
          },
        ),
        throwsA(same(failed)),
      );
      expect(client.calls, ['quote', 'issue', 'bind:$_tx']);
      expect(client.calls.contains('refund'), isFalse);
    });

    test('ผูกรอบแรกพลาด: ลองผูกอีกครั้ง (ทั้งตอนสำเร็จและตอนล้ม) ไม่คืน', () async {
      final ok = _FakeClient()..bindResults = [false, true];
      await TradingFeeFlow.send<bool>(
        plan: await _plan(ok),
        client: ok,
        send: (onSent) async {
          onSent(_tx);
          return true;
        },
      );
      expect(ok.calls,
          ['quote', 'issue', 'bind:$_tx', 'bind:$_tx', 'consume:$_tx']);

      final bad = _FakeClient()..bindResults = [false, true];
      await expectLater(
        TradingFeeFlow.send<bool>(
          plan: await _plan(bad),
          client: bad,
          send: (onSent) async {
            onSent(_tx);
            throw StateError('lost');
          },
        ),
        throwsStateError,
      );
      expect(bad.calls, ['quote', 'issue', 'bind:$_tx', 'bind:$_tx']);
    });

    test('ขอใบไม่สำเร็จ = ไม่ส่งไม้เลย', () async {
      final client = _FakeClient()..issueResult = const TicketIssue();
      final plan = await _plan(client);
      var sends = 0;
      await expectLater(
        TradingFeeFlow.send<bool>(
          plan: plan,
          client: client,
          send: (onSent) async {
            sends++;
            return true;
          },
        ),
        throwsA(isA<TradingFeeException>()
            .having((e) => e.block, 'block', TradingFeeBlock.ticketFailed)),
      );
      expect(sends, 0);
      expect(client.calls, ['quote', 'issue']);
    });

    test('ขอใบแล้วเครดิตไม่พอ (หมดระหว่างทาง) = ให้ไปเติมเครดิต ไม่ส่งไม้', () async {
      final client = _FakeClient()
        ..issueResult = const TicketIssue(
            errorCode: 'INSUFFICIENT_CREDIT', errorMessage: 'เครดิต TPIX ไม่พอ');
      final plan = await _plan(client);
      await expectLater(
        TradingFeeFlow.send<bool>(
          plan: plan,
          client: client,
          send: (onSent) async => true,
        ),
        throwsA(isA<TradingFeeException>()
            .having((e) => e.needsCredit, 'needsCredit', isTrue)),
      );
    });

    test('send จบโดยไม่มีธุรกรรมออก (ไม่ควรเกิด) = คืนค่าบริการ', () async {
      final client = _FakeClient();
      await TradingFeeFlow.send<bool>(
        plan: await _plan(client),
        client: client,
        send: (onSent) async => true,
      );
      expect(client.calls, ['quote', 'issue', 'refund']);
    });
  });
}
