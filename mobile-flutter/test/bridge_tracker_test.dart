/// TPIX TRADE — เทสต์ตัวติดตามรายการของสะพาน USDT
///
/// สิ่งที่ต้องกันพลาด:
///   - ส่งแล้วต้องจด hash ไว้ในเครื่องเสมอ (ปิดหน้า/ปิดแอปแล้วกลับมาติดตามต่อได้ — แม้หน้าถูกปิดระหว่างส่ง)
///   - ถามเฉพาะรายการที่ยังไม่จบ · จบหมด = หยุดตั้งเวลา (ไม่ยิงเซิร์ฟเวอร์ทิ้งไปเรื่อยๆ)
///   - สลับกระเป๋าแล้วไม่โชว์รายการของใบอื่น
///   - โดนจำกัดความถี่ (429) = พักรอบนี้ · ลบรายการแล้วไม่ถูกดึงกลับ
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/services/bridge_rules.dart';
import 'package:tpix_trade/services/bridge_service.dart';
import 'package:tpix_trade/services/bridge_tracker.dart';

const _a = '0xaaaa000000000000000000000000000000001111';
const _b = '0xbbbb000000000000000000000000000000002222';
String hashOf(int i) => '0x${i.toRadixString(16).padLeft(64, '0')}';

TrackedTransfer deposit(int i, {String owner = _a}) =>
    TrackedTransfer(kind: usdtDeposit, chainId: 56, txHash: hashOf(i), owner: owner);

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('เพิ่มแล้วจดลงเครื่องทันที — ตัวติดตามใหม่ (เปิดหน้าใหม่) อ่านกลับได้ พร้อมสถานะล่าสุด', () async {
    final t1 = BridgeTracker(fetcher: (_) async => const BridgeStatusView(state: 'minted'));
    t1.setOwner(_a);
    await t1.load();
    await t1.add(deposit(1));
    await t1.pollOnce();
    t1.dispose();

    final t2 = BridgeTracker(fetcher: (_) async => throw StateError('ห้ามถามซ้ำรายการที่จบแล้ว'));
    t2.setOwner(_a);
    await t2.load();
    expect(t2.entries.single.txHash, hashOf(1));
    expect(t2.entries.single.status?.state, 'minted');
    expect(t2.hasActive, isFalse);
    expect(t2.isTicking, isFalse);
    t2.dispose();
  });

  test('รายการยังไม่จบ = ตั้งเวลาถาม · จบแล้ว = หยุดตั้งเวลา · ถอนถามด้วย /withdrawals', () async {
    var state = 'confirming';
    final kinds = <String>[];
    final t = BridgeTracker(fetcher: (e) async {
      kinds.add(e.kind);
      return BridgeStatusView(state: state);
    });
    t.setOwner(_a);
    await t.load();
    await t.add(TrackedTransfer(kind: usdtWithdraw, chainId: 4289, txHash: hashOf(1), owner: _a, targetChainId: 56));
    await t.pollOnce();
    expect(t.entries.single.status?.state, 'confirming');
    expect(t.isTicking, isTrue);

    state = 'paid';
    await t.pollOnce();
    expect(t.entries.single.status?.state, 'paid');
    expect(t.isTicking, isFalse);
    expect(kinds.toSet(), {usdtWithdraw});
    t.dispose();
  });

  test('chain_paused ยังไม่จบ (ถามต่อ) · chain_disabled จบแล้ว', () async {
    var state = 'chain_paused';
    final t = BridgeTracker(fetcher: (_) async => BridgeStatusView(state: state));
    t.setOwner(_a);
    await t.load();
    await t.add(deposit(1));
    await t.pollOnce();
    expect(t.isTicking, isTrue);
    state = 'chain_disabled';
    await t.pollOnce();
    expect(t.isTicking, isFalse);
    t.dispose();
  });

  test('สลับกระเป๋า → เห็นเฉพาะรายการของใบนั้น', () async {
    final t = BridgeTracker(fetcher: (_) async => const BridgeStatusView(state: 'minted'));
    t.setOwner(_a);
    await t.load();
    await t.add(deposit(1, owner: _a));
    await t.add(deposit(2, owner: _b));
    expect(t.entries.map((e) => e.txHash), [hashOf(1)]);
    t.setOwner(_b);
    expect(t.entries.map((e) => e.txHash), [hashOf(2)]);
    t.setOwner(null);
    expect(t.entries, isEmpty);
    t.dispose();
  });

  test('หน้าถูกปิดระหว่างรอกระเป๋า → add หลัง dispose ยังจดลงเครื่อง', () async {
    final t = BridgeTracker(fetcher: (_) async => const BridgeStatusView(state: 'pending'));
    t.setOwner(_a);
    await t.load();
    t.dispose();
    await t.add(deposit(7));

    final reopened = BridgeTracker(fetcher: (_) async => const BridgeStatusView(state: 'pending'));
    reopened.setOwner(_a);
    await reopened.load();
    expect(reopened.entries.single.txHash, hashOf(7));
    reopened.dispose();
  });

  test('429 = พักรอบนี้ ไม่ถามรายการถัดไป · error อื่นไม่ล้างสถานะเดิม', () async {
    var calls = 0;
    Object? fail = const BridgeRateLimitedException();
    final t = BridgeTracker(fetcher: (_) async {
      calls++;
      if (fail != null) throw fail;
      return const BridgeStatusView(state: 'confirming');
    });
    t.setOwner(_a);
    await t.load();
    await t.add(deposit(1));
    await t.add(deposit(2));
    await t.pollOnce(); // รอรอบที่ add สั่งไว้ให้จบก่อน
    calls = 0;
    await t.pollOnce();
    expect(calls, 1);

    fail = null;
    await t.pollOnce();
    expect(t.entries.every((e) => e.status?.state == 'confirming'), isTrue);

    fail = const BridgeUnavailableException();
    await t.pollOnce();
    expect(t.entries.every((e) => e.status?.state == 'confirming'), isTrue);
    t.dispose();
  });

  test('ลบรายการ → ไม่ถูกดึงกลับจากเครื่องตอนบันทึกรอบถัดไป', () async {
    final t = BridgeTracker(fetcher: (_) async => const BridgeStatusView(state: 'minted'));
    t.setOwner(_a);
    await t.load();
    await t.add(deposit(1));
    await t.add(deposit(2));
    await t.remove(t.entries.firstWhere((e) => e.txHash == hashOf(1)));
    await t.add(deposit(3));
    expect(t.entries.map((e) => e.txHash).toSet(), {hashOf(2), hashOf(3)});
    t.dispose();
  });
}
