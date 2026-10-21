/// TPIX TRADE — เทสต์หน้าสะพาน USDT (API/เชน/กระเป๋าปลอม ไม่ยิงอะไรจริง)
///
/// สิ่งที่ต้องกันพลาด:
///   - เซิร์ฟเวอร์บอกปิด/ตั้งค่าไม่ครบ = ไม่มีฟอร์มให้กดเลย
///   - เชนที่ปิด (ETH/Polygon/Arbitrum/OP/Avalanche) ไม่ถูกเสนอให้เลือก ทั้งฝากและถอน
///   - ปุ่มส่งกดไม่ได้จนกว่าติ๊กยอมรับ + จำนวนผ่าน · ฝากแล้วส่งบนเชน 56 ผ่าน WalletProvider
///     พร้อมตรวจ chain id + กระเป๋าที่คาด · จด hash ลงเครื่อง · กดซ้ำระหว่างรอกระเป๋า = ไม่ส่งซ้ำ
library;

import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/locale/locale_provider.dart';
import 'package:tpix_trade/providers/accent_provider.dart';
import 'package:tpix_trade/providers/wallet_provider.dart';
import 'package:tpix_trade/screens/bridge/bridge_screen.dart';
import 'package:tpix_trade/services/bridge_rules.dart';
import 'package:tpix_trade/services/bridge_service.dart';
import 'package:tpix_trade/services/bridge_tracker.dart';
import 'package:tpix_trade/widgets/common/gradient_button.dart';

import 'bridge_service_test.dart' show livePayload, bscUsdt, vault;

const _wallet = '0xabcdef0123456789abcdef0123456789abcdef01';
final _e18 = BigInt.from(10).pow(18);

class _FakeApi extends BridgeApi {
  _FakeApi(this.payload);

  Map<String, dynamic> payload;

  @override
  Future<UsdtBridgeInfo> fetchInfo() async => UsdtBridgeInfo.fromServer(payload);

  @override
  Future<List<BridgeHistoryRow>> fetchHistory(String wallet) async => const [];

  @override
  Future<BridgeStatusView> fetchDepositStatus(int chainId, String txHash) async =>
      const BridgeStatusView(state: 'confirming');

  @override
  Future<BridgeStatusView> fetchWithdrawal(String txHash) async => const BridgeStatusView(state: 'queued');
}

class _FakeOnChain extends BridgeOnChain {
  const _FakeOnChain();

  @override
  Future<BigInt> tokenBalance(int chainId, String token, String owner) async =>
      chainId == 56 ? BigInt.from(100) * _e18 : BigInt.from(50000000);

  @override
  Future<BridgeTxPlan> prepare({
    required int chainId,
    required String from,
    required String to,
    required Uint8List data,
    BridgeSpend? spend,
  }) async =>
      BridgeTxPlan(
        chainId: chainId,
        from: from,
        to: to,
        data: data,
        gasLimit: 65000,
        gasPrice: chainId == 56 ? BigInt.from(1000000000) : BigInt.zero,
        nativeSymbol: chainId == 56 ? 'BNB' : 'TPIX',
      );
}

class _SendCall {
  final int chainId;
  final String to;
  final Uint8List? data;
  final String? expectedFrom;
  final bool verifyChainId;
  _SendCall(this.chainId, this.to, this.data, this.expectedFrom, this.verifyChainId);
}

class _FakeWallet extends WalletProvider {
  _FakeWallet(this._addr);

  final String? _addr;
  final sends = <_SendCall>[];

  /// ตั้งไว้ = ค้างจนกว่าเทสต์จะ complete เอง (จำลองรอผู้ใช้ยืนยันในกระเป๋า)
  Completer<String?>? hold;

  @override
  String? get address => _addr;

  @override
  bool get isConnected => _addr != null;

  @override
  Future<String?> sendChainTransaction({
    required int chainId,
    required String to,
    Uint8List? data,
    BigInt? value,
    String? summary,
    String? expectedFrom,
    bool verifyChainId = false,
  }) async {
    sends.add(_SendCall(chainId, to, data, expectedFrom, verifyChainId));
    if (hold != null) return hold!.future;
    return '0x${'cd' * 32}';
  }
}

Future<_FakeWallet> _pump(
  WidgetTester tester, {
  required Map<String, dynamic> payload,
  String? wallet = _wallet,
  String lang = 'en',
}) async {
  tester.view.physicalSize = const Size(900, 4200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  SharedPreferences.setMockInitialValues({
    'app_locale': lang,
    'accent_fireflies': false,
    'accent_reduce_motion': true,
  });
  final locale = LocaleProvider();
  await locale.init();
  final accent = AccentProvider();
  await accent.ready;
  final w = _FakeWallet(wallet);
  final api = _FakeApi(payload);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<LocaleProvider>.value(value: locale),
        ChangeNotifierProvider<AccentProvider>.value(value: accent),
        ChangeNotifierProvider<WalletProvider>.value(value: w),
      ],
      child: MaterialApp(
        home: BridgeScreen(
          api: api,
          onChain: const _FakeOnChain(),
          tracker: BridgeTracker(api: api),
        ),
      ),
    ),
  );
  await tester.pump(); // post-frame → โหลด
  await tester.pump();
  await tester.pump();
  return w;
}

Future<void> _unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

GradientButton _button(WidgetTester tester, String key) =>
    tester.widget<GradientButton>(find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(GradientButton)));

void main() {
  testWidgets('เซิร์ฟเวอร์บอกปิด → แถบแจ้งปิด ไม่มีฟอร์ม/ปุ่มส่ง', (tester) async {
    await _pump(tester, payload: livePayload(enabled: false));
    expect(find.byKey(const ValueKey('bridge-closed')), findsOneWidget);
    expect(find.byKey(const ValueKey('bridge-tabs')), findsNothing);
    expect(find.byKey(const ValueKey('bridge-deposit-button')), findsNothing);
    expect(find.byKey(const ValueKey('bridge-source-picker')), findsNothing);
    await _unmount(tester);
  });

  testWidgets('เว็บบอกเปิดแต่ข้อมูลสัญญาผิด (chain id) → ปิดฟอร์มเหมือนกัน', (tester) async {
    await _pump(tester, payload: livePayload(tpixChainId: 1));
    expect(find.byKey(const ValueKey('bridge-closed')), findsOneWidget);
    expect(find.byKey(const ValueKey('bridge-deposit-button')), findsNothing);
    await _unmount(tester);
  });

  testWidgets('เชนที่ปิดอยู่ไม่ถูกเสนอ — ฝาก/ถอนเลือกได้แค่ BSC', (tester) async {
    await _pump(tester, payload: livePayload());
    expect(find.byKey(const ValueKey('bridge-closed')), findsNothing);
    expect(find.byKey(const ValueKey('bridge-chain-56')), findsOneWidget);
    for (final off in [1, 137, 42161, 10, 43114]) {
      expect(find.byKey(ValueKey('bridge-chain-$off')), findsNothing, reason: 'deposit $off');
    }
    expect(find.text('Ethereum'), findsNothing);
    expect(find.text('Polygon PoS'), findsNothing);
    // ยอดทั้งสองฝั่ง: USDT บน BSC (18 ทศนิยม) + USDT บน TPIX Chain
    expect(find.text('100 USDT'), findsWidgets);
    expect(find.text('50 USDT'), findsWidgets);

    await tester.tap(find.text('Withdraw'));
    await tester.pump();
    expect(find.byKey(const ValueKey('bridge-target-picker')), findsOneWidget);
    expect(find.byKey(const ValueKey('bridge-chain-56')), findsOneWidget);
    for (final off in [1, 137, 42161, 10, 43114]) {
      expect(find.byKey(ValueKey('bridge-chain-$off')), findsNothing, reason: 'withdraw $off');
    }
    await _unmount(tester);
  });

  testWidgets('ไม่ได้เชื่อมกระเป๋า → ชวนเชื่อม ไม่มีฟอร์ม', (tester) async {
    await _pump(tester, payload: livePayload(), wallet: null);
    expect(find.byKey(const ValueKey('bridge-connect')), findsOneWidget);
    expect(find.byKey(const ValueKey('bridge-deposit-button')), findsNothing);
    await _unmount(tester);
  });

  testWidgets('ฝาก: ปุ่มเปิดเมื่อจำนวนผ่าน + ติ๊กยอมรับ → ส่งบน BSC พร้อมตรวจ chain id · จด hash · ไม่ส่งซ้ำ', (tester) async {
    final w = await _pump(tester, payload: livePayload());
    w.hold = Completer<String?>();

    expect(_button(tester, 'bridge-deposit-button').onPressed, isNull);

    // ต่ำกว่าขั้นต่ำ → error และกดไม่ได้
    await tester.enterText(find.descendant(of: find.byKey(const ValueKey('bridge-deposit-amount')), matching: find.byType(TextField)), '0.5');
    await tester.pump();
    expect(find.textContaining('Minimum is 1 USDT'), findsOneWidget);

    // เกินเพดานต่อครั้งจริง → ห้ามส่ง
    await tester.enterText(find.descendant(of: find.byKey(const ValueKey('bridge-deposit-amount')), matching: find.byType(TextField)), '1500');
    await tester.pump();
    expect(find.textContaining('Maximum is 1000 USDT'), findsOneWidget);

    await tester.enterText(find.descendant(of: find.byKey(const ValueKey('bridge-deposit-amount')), matching: find.byType(TextField)), '25');
    await tester.pump();
    expect(_button(tester, 'bridge-deposit-button').onPressed, isNull, reason: 'ยังไม่ติ๊กยอมรับ');

    await tester.tap(find.byKey(const ValueKey('bridge-ack')));
    await tester.pump();
    expect(_button(tester, 'bridge-deposit-button').onPressed, isNotNull);

    await tester.tap(find.byKey(const ValueKey('bridge-deposit-button')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    // กล่องยืนยันโชว์ที่อยู่ vault + สัญญา USDT เต็มทุกตัวอักษร
    expect(find.text(vault), findsWidgets);
    expect(find.text(bscUsdt), findsWidgets);
    await tester.tap(find.byKey(const ValueKey('bridge-confirm')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    expect(w.sends.length, 1);
    final call = w.sends.single;
    expect(call.chainId, 56);
    expect(call.to, bscUsdt);
    expect(call.verifyChainId, isTrue);
    expect(call.expectedFrom, _wallet);
    // transfer(vault, 25 × 10^18) — BSC USDT 18 ทศนิยม
    expect(call.data, BridgeTx.encodeTransfer(vault, BigInt.from(25) * _e18));

    // กดซ้ำระหว่างรอกระเป๋า = ไม่ส่งซ้ำ (ปุ่มปิดอยู่)
    expect(_button(tester, 'bridge-deposit-button').onPressed, isNull);
    await tester.tap(find.byKey(const ValueKey('bridge-deposit-button')), warnIfMissed: false);
    await tester.pump();
    expect(w.sends.length, 1);

    w.hold!.complete('0x${'cd' * 32}');
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    await tester.pump(const Duration(milliseconds: 100));

    final prefs = await SharedPreferences.getInstance();
    final stored = decodeTracked(prefs.getString(BridgeTracker.storageKey));
    expect(stored.single.txHash, '0x${'cd' * 32}');
    expect(stored.single.chainId, 56);
    expect(stored.single.kind, usdtDeposit);
    expect(stored.single.amount, '25');
    expect(find.byKey(ValueKey('tracked-deposit:56:0x${'cd' * 32}')), findsOneWidget);
    await _unmount(tester);
  });

  testWidgets('ถอน: bridgeBurn บนเชน 4289 ไปที่อยู่ที่กรอก', (tester) async {
    final w = await _pump(tester, payload: livePayload());
    await tester.tap(find.text('Withdraw'));
    await tester.pump();
    await tester.enterText(find.descendant(of: find.byKey(const ValueKey('bridge-withdraw-amount')), matching: find.byType(TextField)), '25');
    await tester.pump();
    expect(find.textContaining('24.7 USDT'), findsWidgets);
    await tester.tap(find.byKey(const ValueKey('bridge-withdraw-button')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.byKey(const ValueKey('bridge-confirm')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    final call = w.sends.single;
    expect(call.chainId, bridgeTpixChainId);
    expect(call.to, '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1');
    expect(call.data, BridgeTx.encodeBridgeBurn(BigInt.from(25000000), 56, _wallet));
    expect(call.verifyChainId, isTrue);
    await _unmount(tester);
  });
}
