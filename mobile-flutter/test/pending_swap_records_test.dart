/// TPIX TRADE — คิวบันทึกไม้ที่ยังแจ้งเซิร์ฟเวอร์ไม่สำเร็จ (C4 / L8)
///
/// สิ่งที่กันไว้ (กติกาเดียวกับคิวของเว็บ useSwap.js):
///   - 200/201 ที่เซิร์ฟเวอร์มีแถวแล้ว · 409 · 422 = เอาออกจากคิว
///   - เน็ตหลุด · 403 · 5xx · 202 `recorded:false` = เก็บไว้ส่งใหม่ (เดิม 202 ถูกนับเป็นสำเร็จ)
///   - บันทึกอยู่รอดข้ามการปิด-เปิดแอป (เก็บใน SharedPreferences)
///   - ตัว flush ข้ามแถวที่กำลังทำขั้นค่าธรรมเนียม และแถวของกระเป๋าอื่น
///   - ข้อมูลประกอบ pair/side/price ผิดรูปต้องไม่ทำให้ทั้งคำขอโดน 422
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/services/api_service.dart';
import 'package:tpix_trade/services/pending_swap_records.dart';

const _walletA = '0xAAAAaaaaAAAAaaaaAAAAaaaaAAAAaaaaAAAAaaaa';
const _walletB = '0xbbbbBBBBbbbbBBBBbbbbBBBBbbbbBBBBbbbbBBBB';
String _hash(String two) => '0x${two * 32}';

Map<String, dynamic> _record(String tx, {String wallet = _walletA}) =>
    PendingSwapRecords.buildRecord(
      fromToken: '0x55d398326f99059fF775485246999027B3197955',
      toToken: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c',
      fromAmount: 100,
      toAmount: 0.001,
      feeAmount: 0,
      txHash: tx,
      chainId: 56,
      walletAddress: wallet,
      feeCollected: false,
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('ผลของ POST /swap/execute → เก็บหรือทิ้ง', () {
    test('ตัดสินจาก HTTP status + เนื้อคำตอบ (กติกาเดียวกับเว็บ)', () {
      const ok = {
        'success': true,
        'data': {'recorded': true, 'status': 'confirmed'},
      };
      expect(ApiService.swapRecordOutcome(201, ok), SwapRecordOutcome.recorded);
      expect(ApiService.swapRecordOutcome(200, ok), SwapRecordOutcome.recorded);
      // 202 ที่มีแถวแล้ว (pending) = เซิร์ฟเวอร์รับไว้แล้ว
      expect(
        ApiService.swapRecordOutcome(202, {
          'success': true,
          'data': {'recorded': true, 'status': 'pending'},
        }),
        SwapRecordOutcome.recorded,
      );
      // 202 recorded:false = เชนยังไม่เห็นธุรกรรม — เดิมถูกนับเป็นสำเร็จ
      expect(
        ApiService.swapRecordOutcome(202, {
          'success': true,
          'data': {'recorded': false, 'status': 'pending'},
        }),
        SwapRecordOutcome.notYetOnChain,
      );
      expect(ApiService.swapRecordOutcome(409, null), SwapRecordOutcome.rejected);
      expect(ApiService.swapRecordOutcome(422, null), SwapRecordOutcome.rejected);
      for (final status in [403, 404, 429, 500, 502, 503]) {
        expect(ApiService.swapRecordOutcome(status, null),
            SwapRecordOutcome.retryLater,
            reason: 'HTTP $status');
      }
      expect(ApiService.swapRecordOutcome(null, null), SwapRecordOutcome.offline);
      // 200 ที่ไม่ใช่ JSON ของเรา (หน้า HTML ของ CDN) = ยังไม่รู้ว่าบันทึกแล้ว
      expect(ApiService.swapRecordOutcome(200, '<html>'),
          SwapRecordOutcome.retryLater);
      expect(ApiService.swapRecordOutcome(200, {'success': false}),
          SwapRecordOutcome.retryLater);
    });

    test('shouldKeep: สำเร็จ/ปฏิเสธถาวร = ทิ้ง · อื่น ๆ = เก็บ', () {
      const fresh = Duration(minutes: 1);
      expect(PendingSwapRecords.shouldKeep(SwapRecordOutcome.recorded, fresh),
          isFalse);
      expect(PendingSwapRecords.shouldKeep(SwapRecordOutcome.rejected, fresh),
          isFalse);
      for (final o in [
        SwapRecordOutcome.notYetOnChain,
        SwapRecordOutcome.retryLater,
        SwapRecordOutcome.offline,
      ]) {
        expect(PendingSwapRecords.shouldKeep(o, fresh), isTrue, reason: '$o');
      }
    });

    test('shouldKeep: เชนไม่เห็นธุรกรรมเกิน 1 วัน = ทิ้ง · 403/เน็ตหลุดเก็บต่อ', () {
      const twoDays = Duration(days: 2);
      expect(
          PendingSwapRecords.shouldKeep(
              SwapRecordOutcome.notYetOnChain, twoDays),
          isFalse);
      expect(
          PendingSwapRecords.shouldKeep(SwapRecordOutcome.retryLater, twoDays),
          isTrue);
      expect(PendingSwapRecords.shouldKeep(SwapRecordOutcome.offline, twoDays),
          isTrue);
      expect(
          PendingSwapRecords.shouldKeep(
              SwapRecordOutcome.retryLater, const Duration(days: 31)),
          isFalse);
    });
  });

  group('คิว', () {
    late SwapRecordOutcome outcome;
    late List<Map<String, dynamic>> posted;
    late DateTime now;
    late PendingSwapRecords queue;

    PendingSwapRecords newQueue() => PendingSwapRecords.forTest(
          poster: (payload) async {
            posted.add(payload);
            return outcome;
          },
          now: () => now,
        );

    setUp(() {
      outcome = SwapRecordOutcome.recorded;
      posted = [];
      now = DateTime(2026, 11, 13, 12);
      queue = newQueue();
    });

    test('upsert: หนึ่ง tx = หนึ่งแถว และอยู่รอดข้ามการเปิดแอปใหม่', () async {
      await queue.upsert(_record(_hash('a1')));
      await queue.upsert({..._record(_hash('a1')), 'fee_collected': true});
      await queue.upsert(_record(_hash('b2')));

      // คิวตัวใหม่ (เหมือนเปิดแอปใหม่) อ่านจากที่เก็บเดิม
      final reopened = await newQueue().all();
      expect(reopened, hasLength(2));
      final a1 = reopened.firstWhere((r) => r['tx_hash'] == _hash('a1'));
      expect(a1['fee_collected'], isTrue);
    });

    test('submit: 200/201 · 409 · 422 = เอาออกจากคิว', () async {
      for (final o in [SwapRecordOutcome.recorded, SwapRecordOutcome.rejected]) {
        await queue.upsert(_record(_hash('a1')));
        outcome = o;
        expect(await queue.submit(_hash('a1')), o);
        expect(await queue.all(), isEmpty, reason: '$o');
      }
    });

    test('submit: เน็ตหลุด · 403/5xx · 202 ไม่มีแถว = เก็บไว้', () async {
      await queue.upsert(_record(_hash('a1')));
      for (final o in [
        SwapRecordOutcome.offline,
        SwapRecordOutcome.retryLater,
        SwapRecordOutcome.notYetOnChain,
      ]) {
        outcome = o;
        expect(await queue.submit(_hash('a1')), o);
        expect(await queue.all(), hasLength(1), reason: '$o');
      }
    });

    test('submit: ตัวส่งโยน exception = ถือว่าเน็ตหลุด เก็บไว้', () async {
      final throwing = PendingSwapRecords.forTest(
          poster: (_) async => throw StateError('boom'));
      await throwing.upsert(_record(_hash('a1')));
      expect(await throwing.submit(_hash('a1')), SwapRecordOutcome.offline);
      expect(await throwing.all(), hasLength(1));
    });

    test('เนื้อคำขอไม่มีช่องภายในของคิว (_queuedAt)', () async {
      await queue.upsert(_record(_hash('a1')));
      await queue.submit(_hash('a1'));
      expect(posted.single.keys.where((k) => k.startsWith('_')), isEmpty);
      expect(posted.single['tx_hash'], _hash('a1'));
    });

    test('flush: ส่งเฉพาะของกระเป๋านี้ ข้ามแถวที่กำลังทำขั้นค่าธรรมเนียม', () async {
      await queue.upsert(_record(_hash('a1')));
      await queue.upsert(_record(_hash('b2'), wallet: _walletB));
      await queue.upsert(_record(_hash('c3')));
      queue.markInflight(_hash('c3'));

      // ที่อยู่กระเป๋าเทียบแบบไม่สนตัวพิมพ์
      expect(await queue.flush(_walletA.toLowerCase()), 1);
      expect(posted.map((p) => p['tx_hash']), [_hash('a1')]);
      final left = (await queue.all()).map((r) => r['tx_hash']).toSet();
      expect(left, {_hash('b2'), _hash('c3')});

      // ขั้นค่าธรรมเนียมจบแล้ว — รอบถัดไปส่งได้
      queue.clearInflight(_hash('c3'));
      expect(await queue.flush(_walletA), 1);
      expect((await queue.all()).single['tx_hash'], _hash('b2'));
    });

    test('flush: ยิงไม่ถึงเซิร์ฟเวอร์ = หยุดรอบนี้ทันที ไม่ไล่ยิงทุกแถว', () async {
      await queue.upsert(_record(_hash('a1')));
      await queue.upsert(_record(_hash('b2')));
      await queue.upsert(_record(_hash('c3')));
      outcome = SwapRecordOutcome.offline;
      expect(await queue.flush(_walletA), 0);
      expect(posted, hasLength(1));
      expect(await queue.all(), hasLength(3));
    });

    test('flush: 403 เก็บทุกแถวไว้ (ยืนยันกระเป๋า/ตัวตนแล้วค่อยส่งผ่าน)', () async {
      await queue.upsert(_record(_hash('a1')));
      await queue.upsert(_record(_hash('b2')));
      outcome = SwapRecordOutcome.retryLater;
      expect(await queue.flush(_walletA), 0);
      expect(posted, hasLength(2));
      expect(await queue.all(), hasLength(2));

      outcome = SwapRecordOutcome.recorded;
      expect(await queue.flush(_walletA), 2);
      expect(await queue.all(), isEmpty);
    });

    test('flush: 202 ที่เชนไม่เห็นธุรกรรมเกิน 1 วัน = ทิ้ง (นับจากเวลาเข้าคิวครั้งแรก)', () async {
      await queue.upsert(_record(_hash('a1')));
      outcome = SwapRecordOutcome.notYetOnChain;

      now = now.add(const Duration(hours: 23));
      // upsert ซ้ำต้องไม่รีเซ็ตเวลาเข้าคิว
      await queue.upsert(_record(_hash('a1')));
      await queue.flush(_walletA);
      expect(await queue.all(), hasLength(1));

      now = now.add(const Duration(hours: 2));
      await queue.flush(_walletA);
      expect(await queue.all(), isEmpty);
    });

    test('flush: ไม่มีกระเป๋า / คิวว่าง = ไม่ยิงอะไร', () async {
      expect(await queue.flush(null), 0);
      expect(await queue.flush(''), 0);
      expect(await queue.flush(_walletA), 0);
      expect(posted, isEmpty);
    });

    test('เก็บได้ไม่เกิน 20 แถว — เกินแล้วทิ้งแถวเก่าสุด', () async {
      for (var i = 0; i < 23; i++) {
        await queue.upsert(_record(_hash(i.toRadixString(16).padLeft(2, '0'))));
      }
      final all = await queue.all();
      expect(all, hasLength(PendingSwapRecords.maxRecords));
      expect(all.any((r) => r['tx_hash'] == _hash('00')), isFalse);
      expect(all.any((r) => r['tx_hash'] == _hash('16')), isTrue);
    });

    test('ที่เก็บเสีย (ไม่ใช่ JSON) = คิวว่าง ไม่ crash', () async {
      SharedPreferences.setMockInitialValues(
          {PendingSwapRecords.storageKey: 'not json'});
      expect(await newQueue().all(), isEmpty);
    });
  });

  group('buildRecord — ข้อมูลประกอบ pair / side / price (L8)', () {
    Map<String, dynamic> build({
      String? pair,
      String? side,
      double? price,
      double from = 300,
      double to = 1,
    }) =>
        PendingSwapRecords.buildRecord(
          fromToken: '0x55d398326f99059fF775485246999027B3197955',
          toToken: '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE',
          fromAmount: from,
          toAmount: to,
          feeAmount: 0.9,
          txHash: _hash('a1'),
          chainId: 56,
          walletAddress: _walletA,
          feeCollected: true,
          feeTxHash: _hash('f0'),
          pair: pair,
          side: side,
          price: price,
        );

    test('ซื้อ: pair เป็น BASE/QUOTE ราคา = จ่าย ÷ ได้', () {
      final r = build(pair: 'BNB-USDT', side: 'buy', price: 310);
      expect(r['pair'], 'BNB/USDT');
      expect(r['side'], 'buy');
      expect(r['price'], closeTo(300, 1e-9));
      expect(r['fee_tx_hash'], _hash('f0'));
      expect(r['fee_collected'], isTrue);
    });

    test('ขาย: ราคา = ได้ ÷ จ่าย', () {
      final r = build(pair: 'BNB/USDT', side: 'SELL', from: 2, to: 600);
      expect(r['side'], 'sell');
      expect(r['price'], closeTo(300, 1e-9));
    });

    test('คิดราคาจากยอดไม่ได้ = ใช้ราคาตลาดที่ส่งมา', () {
      final r = build(pair: 'BNB-USDT', side: 'buy', price: 310, to: 0);
      expect(r['price'], 310);
    });

    test('ไม่รู้คู่/ฝั่ง (หน้า Swap) = ไม่ส่งช่องนั้น', () {
      final r = build();
      expect(r.containsKey('pair'), isFalse);
      expect(r.containsKey('side'), isFalse);
      expect(r.containsKey('price'), isFalse);
    });

    test('ค่าผิดรูปถูกตัดทิ้ง ไม่ทำให้ทั้งคำขอโดน 422', () {
      final r = build(pair: 'not a pair!', side: 'swap', price: -1);
      expect(r.containsKey('pair'), isFalse);
      expect(r.containsKey('side'), isFalse);
      expect(r.containsKey('price'), isFalse);
    });
  });
}
