/// TPIX TRADE — ด่านก่อนเงินออกจากกระเป๋า: ยืนยันกระเป๋า + ด่านยืนยันตัวตนของการเทรด (C5)
///
/// สิ่งที่กันไว้:
///   - กระเป๋ายังไม่ยืนยันกับเซิร์ฟเวอร์ → ยืนยันก่อน ไม่ผ่าน = ไม่ส่งไม้
///   - แอดมินเปิดด่าน `trading` และบัญชียังไม่ผ่าน → ไม่ส่งไม้ (พาไปหน้ายืนยันตัวตน)
///   - อ่านสถานะด่านไม่ได้ และไม่มีค่าที่เพิ่งอ่านไว้ → ไม่ส่งไม้ (fail-closed)
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/kyc_models.dart';
import 'package:tpix_trade/providers/kyc_store.dart';
import 'package:tpix_trade/services/api_result.dart';
import 'package:tpix_trade/services/trade_preflight.dart';

const _wallet = '0x1111111111111111111111111111111111111111';

KycStatus _status({bool required = false, bool passed = false}) =>
    KycStatus.fromJson({
      'gate': {
        'enabled': true,
        'approved_level': passed ? 'basic' : null,
        'features': {
          'trading': {'required': required, 'level': 'basic', 'passed': passed},
          'ai_bot': {'required': true, 'level': 'basic', 'passed': false},
        },
      },
    });

void main() {
  late KycStore store;
  late DateTime now;
  late int fetches;
  late int verifies;

  setUp(() {
    store = KycStore();
    now = DateTime.now();
    fetches = 0;
    verifies = 0;
  });

  Future<void> run({
    bool verified = true,
    bool verifyOk = true,
    required List<ApiResult<KycStatus>> responses,
  }) {
    var isVerified = verified;
    return TradePreflight.ensureCanTrade(
      address: _wallet,
      isVerified: () => isVerified,
      verify: () async {
        verifies++;
        isVerified = verifyOk;
        return verifyOk;
      },
      fetchStatus: (wallet) async {
        final i = fetches < responses.length ? fetches : responses.length - 1;
        fetches++;
        return responses[i];
      },
      store: store,
      now: () => now,
    );
  }

  Matcher blocked(TradeBlockReason reason) => throwsA(
      isA<TradeBlockedException>().having((e) => e.reason, 'reason', reason));

  group('kycBlocksTrading — กติกาเดียวกับเว็บ', () {
    test('ด่าน trading บังคับและยังไม่ผ่าน = บล็อก', () {
      expect(
          TradePreflight.kycBlocksTrading(_status(required: true).gate), isTrue);
    });

    test('ไม่บังคับ / ผ่านแล้ว = ไม่บล็อก (ด่านของฟีเจอร์อื่นไม่เกี่ยว)', () {
      expect(TradePreflight.kycBlocksTrading(_status().gate), isFalse);
      expect(
          TradePreflight.kycBlocksTrading(
              _status(required: true, passed: true).gate),
          isFalse);
    });

    test('เซิร์ฟเวอร์ไม่ได้ส่งด่าน trading มา = ไม่มีด่าน', () {
      expect(TradePreflight.kycBlocksTrading(KycGateStatus.empty), isFalse);
    });
  });

  test('ยืนยันแล้ว + ไม่มีด่าน = ผ่าน และเก็บสถานะไว้ให้หน้า KYC', () async {
    await run(responses: [ApiOk(_status())]);
    expect(verifies, 0);
    expect(fetches, 1);
    expect(store.statusFor(_wallet), isNotNull);
  });

  test('ยังไม่ยืนยันกระเป๋า: ยืนยันก่อน — ไม่ผ่าน = ไม่ส่งไม้ และไม่ถามด่านต่อ', () async {
    await expectLater(
      run(verified: false, verifyOk: false, responses: [ApiOk(_status())]),
      blocked(TradeBlockReason.walletNotVerified),
    );
    expect(verifies, 1);
    expect(fetches, 0);
  });

  test('ยังไม่ยืนยันกระเป๋า: ยืนยันผ่าน = ไปต่อ', () async {
    await run(verified: false, responses: [ApiOk(_status())]);
    expect(verifies, 1);
    expect(fetches, 1);
  });

  test('ด่านยืนยันตัวตนบังคับและยังไม่ผ่าน = ไม่ส่งไม้ (ต้องไปยืนยันตัวตน)', () async {
    await expectLater(
      run(responses: [ApiOk(_status(required: true))]),
      throwsA(isA<TradeBlockedException>()
          .having((e) => e.needsKyc, 'needsKyc', isTrue)),
    );
  });

  test('เซสชันฝั่งเซิร์ฟเวอร์หมดอายุ (403): เซ็นใหม่หนึ่งครั้งแล้วถามซ้ำ', () async {
    await run(responses: [
      const ApiErr('WALLET_NOT_VERIFIED', 'x', status: 403),
      ApiOk(_status()),
    ]);
    expect(verifies, 1);
    expect(fetches, 2);
  });

  test('403 แล้วเซ็นใหม่ไม่ผ่าน = ไม่ส่งไม้', () async {
    await expectLater(
      run(verifyOk: false, responses: [
        const ApiErr('WALLET_NOT_VERIFIED', 'x', status: 403),
      ]),
      blocked(TradeBlockReason.walletNotVerified),
    );
  });

  test('อ่านสถานะด่านไม่ได้ และไม่เคยอ่านไว้ = ไม่ส่งไม้ (fail-closed)', () async {
    await expectLater(
      run(responses: [const ApiErr('NETWORK', 'x')]),
      blocked(TradeBlockReason.kycUnknown),
    );
    await expectLater(
      run(responses: [const ApiErr('HTTP_500', 'x', status: 500)]),
      blocked(TradeBlockReason.kycUnknown),
    );
  });

  test('ค่าที่เพิ่งอ่าน (≤ 60 วิ) ใช้ได้เลย ไม่ถามเซิร์ฟเวอร์ซ้ำ', () async {
    store.update(_wallet, _status());
    await run(responses: [const ApiErr('NETWORK', 'x')]);
    expect(fetches, 0);

    store.update(_wallet, _status(required: true));
    await expectLater(run(responses: [ApiOk(_status())]),
        blocked(TradeBlockReason.kycRequired));
    expect(fetches, 0);
  });

  test('ถามไม่ได้ แต่มีค่าที่อ่านไว้ไม่เกิน 10 นาที = ใช้ค่านั้น · เก่ากว่านั้น = ไม่ส่งไม้', () async {
    store.update(_wallet, _status());
    now = DateTime.now().add(const Duration(minutes: 5));
    await run(responses: [const ApiErr('NETWORK', 'x')]);
    expect(fetches, 1);

    now = DateTime.now().add(const Duration(minutes: 11));
    await expectLater(
      run(responses: [const ApiErr('NETWORK', 'x')]),
      blocked(TradeBlockReason.kycUnknown),
    );
  });

  test('ค่าที่อ่านไว้ของกระเป๋าใบอื่นไม่ถูกเอามาใช้', () async {
    store.update('0x2222222222222222222222222222222222222222', _status());
    await expectLater(
      run(responses: [const ApiErr('NETWORK', 'x')]),
      blocked(TradeBlockReason.kycUnknown),
    );
  });
}
