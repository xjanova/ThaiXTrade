/// TPIX TRADE — เทสต์หน้าจอ "ยืนยันตัวตน" ด้วย API ปลอม
///
/// วาดทุกสถานะหลักจริง (ไม่ใช่แค่เทียบ enum) และเดินเส้นทางที่เคยพังในแอปอื่นของบ้าน:
/// ปุ่มต้องกดไม่ได้จนกว่าจะยินยอม · ถามผลซ้ำต้องหยุดเองเมื่อโดนจำกัดอัตรา ·
/// หน้าที่เปิดค้างต้องอัปเดตเองเมื่อ deep link แลกรหัสสำเร็จ · ปิดหน้าระหว่างรอต้องไม่พัง
///
/// Developed by Xman Studio
library;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/locale/locale_provider.dart';
import 'package:tpix_trade/models/kyc_models.dart';
import 'package:tpix_trade/providers/accent_provider.dart';
import 'package:tpix_trade/providers/kyc_store.dart';
import 'package:tpix_trade/providers/wallet_provider.dart';
import 'package:tpix_trade/screens/kyc/kyc_screen.dart';
import 'package:tpix_trade/services/api_result.dart';
import 'package:tpix_trade/services/kyc_api.dart';
import 'package:tpix_trade/widgets/common/gradient_button.dart';

const _wallet = '0xabcdef0123456789abcdef0123456789abcdef01';

final _openAppButton = find.widgetWithText(GradientButton, 'เปิดแอป Thaiprompt');

KycStatus _status({
  Map<String, dynamic>? submission,
  bool available = true,
  Map<String, dynamic>? link,
}) =>
    KycStatus.fromJson({
      'gate': {
        'enabled': true,
        'approved_level': submission?['status'] == 'approved' ? 'basic' : null,
        'features': {
          'ai_bot': {
            'required': true,
            'level': 'basic',
            'passed': submission?['status'] == 'approved',
          },
          'bridge': {'required': false, 'level': 'basic', 'passed': true},
        },
      },
      'submission': submission,
      'thaiprompt': {
        'available': available,
        'link': link,
        'app_link': 'thaiprompt://ekyc?from=profile',
        'download_url': 'https://main.thaiprompt.online/app/download',
      },
      'web_kyc_url': 'https://tpix.online/kyc',
    });

final _approved = _status(
  submission: {
    'status': 'approved',
    'level': 'basic',
    'source': 'thaiprompt',
    'submitted_at': '2026-10-05T12:00:00+00:00',
    'reviewed_at': '2026-10-05T12:00:00+00:00',
  },
  link: {'status': 'approved'},
);

/// API ปลอม — ตั้งผลของแต่ละเส้นไว้ล่วงหน้า และนับว่าถูกเรียกกี่ครั้ง
class _FakeKycApi implements KycApi {
  ApiResult<KycStatus> status = ApiOk(_status());
  ApiResult<Uri> start =
      ApiOk(Uri.parse('https://main.thaiprompt.online/oauth/authorize?x=1'));
  ApiResult<KycStatus> refresh = ApiOk(_status(link: {'status': 'none'}));

  /// ตั้งไว้ = fetchStatus ค้างจนกว่าเทสต์จะ complete เอง (จำลองเน็ตช้า)
  Completer<ApiResult<KycStatus>>? holdStatus;

  int statusCalls = 0;
  int startCalls = 0;
  int refreshCalls = 0;

  @override
  Future<ApiResult<KycStatus>> fetchStatus(String wallet) async {
    statusCalls++;
    final hold = holdStatus;
    if (hold != null) return hold.future;
    return status;
  }

  @override
  Future<ApiResult<Uri>> startThaiprompt(String wallet) async {
    startCalls++;
    return start;
  }

  @override
  Future<ApiResult<KycStatus>> refreshThaiprompt(String wallet) async {
    refreshCalls++;
    return refresh;
  }

  @override
  Future<ApiResult<KycStatus>> completeThaiprompt({
    required String wallet,
    required String completion,
  }) async =>
      ApiOk(_approved);
}

/// กระเป๋าปลอม — แค่ที่อยู่ + ปุ่มเซ็น (ไม่แตะ secure storage / เน็ต)
class _FakeWallet extends WalletProvider {
  _FakeWallet(this._addr);

  String? _addr;
  int verifyCalls = 0;
  VoidCallback? onVerify;

  @override
  String? get address => _addr;

  @override
  bool get isConnected => _addr != null;

  @override
  Future<bool> verifyWithBackend() async {
    verifyCalls++;
    onVerify?.call();
    return true;
  }

  void switchTo(String? address) {
    _addr = address;
    notifyListeners();
  }
}

class _Harness {
  final _FakeKycApi api;
  final KycStore store;
  final _FakeWallet wallet;
  final List<Uri> opened;

  _Harness(this.api, this.store, this.wallet, this.opened);
}

Future<_Harness> _pump(
  WidgetTester tester, {
  _FakeKycApi? api,
  String? wallet = _wallet,
  String lang = 'th',
  bool openSucceeds = true,
}) async {
  // จอสูง — ให้ทุกการ์ดถูกสร้างจริง (sliver สร้างเฉพาะที่อยู่ในจอ)
  tester.view.physicalSize = const Size(900, 3200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  SharedPreferences.setMockInitialValues({
    'app_locale': lang,
    // ปิดหิ่งห้อย + ลดการเคลื่อนไหว = ไม่มีแอนิเมชันวนไม่รู้จบให้ pump ค้าง
    'accent_fireflies': false,
    'accent_reduce_motion': true,
  });
  final locale = LocaleProvider();
  await locale.init();
  final accent = AccentProvider();
  await accent.ready;

  final h = _Harness(api ?? _FakeKycApi(), KycStore(), _FakeWallet(wallet), []);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<LocaleProvider>.value(value: locale),
        ChangeNotifierProvider<AccentProvider>.value(value: accent),
        ChangeNotifierProvider<WalletProvider>.value(value: h.wallet),
      ],
      child: MaterialApp(
        home: KycScreen(
          api: h.api,
          store: h.store,
          openExternal: (uri) async {
            h.opened.add(uri);
            return openSucceeds;
          },
        ),
      ),
    ),
  );
  await tester.pump(); // post-frame → โหลดสถานะ
  await tester.pump(); // คำตอบกลับมา → วาดใหม่
  return h;
}

/// ปิดหน้าก่อนจบเทสต์ — ตัวจับเวลาถามผลซ้ำต้องถูกยกเลิกใน dispose
Future<void> _unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

void main() {
  testWidgets('ยังไม่เชื่อมกระเป๋า → ชวนเชื่อม ไม่ยิงเน็ต', (tester) async {
    final h = await _pump(tester, wallet: null);

    expect(find.text('เชื่อมกระเป๋าก่อนยืนยันตัวตน'), findsOneWidget);
    expect(find.text('เชื่อมกระเป๋า'), findsOneWidget);
    expect(find.textContaining('หน้าเว็บ'), findsNothing,
        reason: 'หน้าเว็บเลิกรับเอกสารแล้ว — พาไปก็วนกลับมาที่แอป');
    expect(h.api.statusCalls, 0);
    await _unmount(tester);
  });

  testWidgets('ต้องเซ็นกระเป๋า → กดยืนยัน → โหลดใหม่แล้วเห็นผล', (tester) async {
    final api = _FakeKycApi()
      ..status = const ApiErr('WALLET_NOT_VERIFIED', '...', status: 403);
    final h = await _pump(tester, api: api);

    expect(find.text('ยืนยันกระเป๋าก่อน'), findsOneWidget);
    // เซ็นผ่านแล้วเซิร์ฟเวอร์ตอบว่ายืนยันตัวตนแล้ว
    h.wallet.onVerify = () => api.status = ApiOk(_approved);
    await tester.tap(find.text('ยืนยันกระเป๋า'));
    await tester.pump();
    await tester.pump();
    await tester.pump();

    expect(h.wallet.verifyCalls, 1);
    expect(api.statusCalls, 2);
    expect(find.text('ยืนยันตัวตนแล้ว'), findsOneWidget);
    expect(find.text('ยืนยันกระเป๋าแล้ว'), findsOneWidget, reason: 'snackbar');
    await _unmount(tester);
  });

  testWidgets('ผ่านแล้ว (Thaiprompt) → การ์ดสำเร็จ + เฉพาะฟีเจอร์ที่บังคับ', (tester) async {
    final api = _FakeKycApi()..status = ApiOk(_approved);
    await _pump(tester, api: api);

    expect(find.text('ยืนยันตัวตนแล้ว'), findsOneWidget);
    expect(find.textContaining('ผ่านบัญชี Thaiprompt'), findsOneWidget);
    expect(find.textContaining('/10/2026'), findsOneWidget, reason: 'วันที่ยืนยัน');
    expect(find.text('บริการที่ปลดล็อกแล้ว'), findsOneWidget);
    expect(find.text('เช่าบอทเทรด AI'), findsOneWidget);
    expect(find.text('สะพานข้ามเชน'), findsNothing,
        reason: 'ฟีเจอร์ที่ไม่บังคับไม่ต้องโชว์');
    expect(find.text('ยังไม่มีบัญชี Thaiprompt?'), findsNothing,
        reason: 'ผ่านแล้วไม่ต้องชวนสมัคร Thaiprompt');
    await _unmount(tester);
  });

  testWidgets('ภาษาอังกฤษ', (tester) async {
    final api = _FakeKycApi()..status = ApiOk(_approved);
    await _pump(tester, api: api, lang: 'en');

    expect(find.text('Identity verified'), findsOneWidget);
    expect(find.textContaining('via your Thaiprompt account'), findsOneWidget);
    expect(find.text('AI trading bot'), findsOneWidget);
    await _unmount(tester);
  });

  testWidgets('ยังไม่เชื่อม Thaiprompt → ต้องติ๊กยินยอมก่อน แล้วเปิดเบราว์เซอร์',
      (tester) async {
    final h = await _pump(tester);

    expect(find.text('ยืนยันตัวตนด้วยบัญชี Thaiprompt'), findsOneWidget);
    expect(find.text('เคยยืนยันตัวตนใน Thaiprompt แล้ว'), findsOneWidget);
    expect(find.text('ยังไม่เคยยืนยันตัวตนใน Thaiprompt'), findsOneWidget);
    expect(find.text('ยังไม่มีบัญชี Thaiprompt?'), findsOneWidget);

    // ยังไม่ยินยอม = กดไม่ได้
    await tester.tap(find.text('ยืนยันด้วย Thaiprompt'));
    await tester.pump();
    expect(h.api.startCalls, 0);

    await tester.tap(find.byKey(const ValueKey('kyc-consent')));
    await tester.pump();
    await tester.tap(find.text('ยืนยันด้วย Thaiprompt'));
    await tester.pump();
    await tester.pump();

    expect(h.api.startCalls, 1);
    expect(h.opened.single.host, 'main.thaiprompt.online');
    await _unmount(tester);
  });

  testWidgets('start ล้มเหลว → ข้อความไทยของแอป ไม่ใช่ข้อความดิบ', (tester) async {
    final api = _FakeKycApi()
      ..start = const ApiErr('THAIPROMPT_UNAVAILABLE', 'raw server text', status: 422);
    final h = await _pump(tester, api: api);

    await tester.tap(find.byKey(const ValueKey('kyc-consent')));
    await tester.pump();
    await tester.tap(find.text('ยืนยันด้วย Thaiprompt'));
    await tester.pump();
    await tester.pump();

    expect(find.textContaining('ติดต่อ Thaiprompt ไม่ได้ชั่วคราว'), findsOneWidget);
    expect(find.text('raw server text'), findsNothing);
    expect(h.opened, isEmpty);
    await _unmount(tester);
  });

  testWidgets('รอผลจาก Thaiprompt → ถามซ้ำทุก 20 วิ จนผ่าน แล้วโชว์สำเร็จ',
      (tester) async {
    final api = _FakeKycApi()..status = ApiOk(_status(link: {'status': 'none'}));
    final h = await _pump(tester, api: api);

    expect(find.text('รอผลยืนยันตัวตนจาก Thaiprompt'), findsOneWidget);
    expect(find.text('คุณยังไม่ได้ยืนยันตัวตนในแอป Thaiprompt'), findsOneWidget);
    expect(find.text('ถ่ายบัตรประชาชนและสแกนใบหน้า'), findsOneWidget);
    // ขั้นที่ 1 กับปุ่มใช้คำเดียวกัน — หาเฉพาะตัวที่เป็นปุ่ม
    expect(_openAppButton, findsOneWidget);
    expect(find.text('ตรวจสอบอีกครั้ง'), findsOneWidget);
    expect(h.api.refreshCalls, 0);

    // รอบแรก: ยังไม่ผ่าน
    await tester.pump(KycScreen.pollInterval);
    await tester.pump();
    expect(h.api.refreshCalls, 1);
    expect(find.text('รอผลยืนยันตัวตนจาก Thaiprompt'), findsOneWidget);

    // รอบสอง: ผ่าน → โหลดสถานะเต็มอีกรอบแล้วโชว์การ์ดสำเร็จ
    api.refresh = ApiOk(_approved);
    api.status = ApiOk(_approved);
    await tester.pump(KycScreen.pollInterval);
    await tester.pump();
    await tester.pump();
    expect(h.api.refreshCalls, 2);
    expect(find.text('ยืนยันตัวตนแล้ว'), findsOneWidget);

    // ผ่านแล้วต้องเลิกถาม
    await tester.pump(KycScreen.pollInterval * 2);
    expect(h.api.refreshCalls, 2);
    await _unmount(tester);
  });

  testWidgets('โดนจำกัดอัตรา → หยุดถามอัตโนมัติ', (tester) async {
    final api = _FakeKycApi()
      ..status = ApiOk(_status(link: {'status': 'pending'}))
      ..refresh = const ApiErr('RATE_LIMITED', '...', status: 429, retryAfterSeconds: 30);
    final h = await _pump(tester, api: api);

    expect(find.text('Thaiprompt กำลังตรวจสอบข้อมูลของคุณ'), findsOneWidget);
    await tester.pump(KycScreen.pollInterval);
    await tester.pump();
    expect(h.api.refreshCalls, 1);
    expect(find.textContaining('รออีก 30 วินาที'), findsOneWidget);

    await tester.pump(KycScreen.pollInterval * 3);
    expect(h.api.refreshCalls, 1, reason: 'ห้ามยิงต่อหลังโดน 429');
    await _unmount(tester);
  });

  testWidgets('สิทธิ์ Thaiprompt หมดอายุ → กลับไปการ์ดเชื่อมใหม่ด้วยสถานะที่แนบมา',
      (tester) async {
    final api = _FakeKycApi()
      ..status = ApiOk(_status(link: {'status': 'pending'}))
      ..refresh = ApiErr(
        'THAIPROMPT_RECONNECT',
        '...',
        status: 422,
        payload: _status(link: {'status': 'pending', 'needs_reconnect': true}),
      );
    final h = await _pump(tester, api: api);

    await tester.tap(find.text('ตรวจสอบอีกครั้ง'));
    await tester.pump();
    await tester.pump();

    expect(find.text('ยืนยันตัวตนด้วยบัญชี Thaiprompt'), findsOneWidget);
    expect(find.textContaining('สิทธิ์ที่เชื่อมกับ Thaiprompt หมดอายุแล้ว'),
        findsWidgets);
    expect(h.api.statusCalls, 1, reason: 'สถานะแนบมาแล้ว ไม่ต้องถามซ้ำ');
    await _unmount(tester);
  });

  testWidgets('เปิดแอป Thaiprompt ไม่ได้ → เสนอลิงก์ดาวน์โหลด', (tester) async {
    final api = _FakeKycApi()..status = ApiOk(_status(link: {'status': 'none'}));
    final h = await _pump(tester, api: api, openSucceeds: false);

    expect(find.text('ดาวน์โหลดแอป Thaiprompt'), findsNothing);
    await tester.tap(_openAppButton);
    await tester.pump();
    await tester.pump();

    expect(h.opened.single.scheme, 'thaiprompt');
    expect(find.text('ดาวน์โหลดแอป Thaiprompt'), findsOneWidget);
    await _unmount(tester);
  });

  testWidgets('Thaiprompt ปิดอยู่ → บอกว่ายังไม่เปิดให้บริการ ไม่พาไปหน้าเว็บ',
      (tester) async {
    final api = _FakeKycApi()..status = ApiOk(_status(available: false));
    final h = await _pump(tester, api: api);

    expect(find.text('ยืนยันตัวตนยังไม่เปิดให้บริการชั่วคราว'), findsOneWidget);
    expect(find.text('ยืนยันตัวตนด้วยบัญชี Thaiprompt'), findsNothing);

    // ลองใหม่ = ถามสถานะซ้ำ ไม่ใช่เปิดลิงก์ออกไปข้างนอก
    await tester.tap(find.text('ลองใหม่'));
    await tester.pump();
    await tester.pump();
    expect(h.api.statusCalls, 2);
    expect(h.opened, isEmpty);
    await _unmount(tester);
  });

  testWidgets('ยังไม่มีบัญชี Thaiprompt → ดาวน์โหลดแอป Thaiprompt',
      (tester) async {
    final h = await _pump(tester);

    await tester.tap(find.text('ดาวน์โหลด'));
    await tester.pump();
    expect(h.opened.single.toString(),
        'https://main.thaiprompt.online/app/download');
    await _unmount(tester);
  });

  testWidgets('deep link แลกรหัสสำเร็จขณะหน้าเปิดค้าง → อัปเดตเองทันที',
      (tester) async {
    final h = await _pump(tester);
    expect(find.text('ยืนยันตัวตนด้วยบัญชี Thaiprompt'), findsOneWidget);

    // สิ่งที่ DeepLinkService ทำ: แลกรหัสผ่าน store ตัวเดียวกับที่หน้าจอฟังอยู่
    final outcome = await h.store.complete(
      api: h.api,
      wallet: _wallet,
      completion: 'A1' * 24,
    );
    await tester.pump();

    expect(outcome, KycCompleteOutcome.approved);
    expect(find.text('ยืนยันตัวตนแล้ว'), findsOneWidget);
    expect(h.api.statusCalls, 1, reason: 'ไม่ต้องโหลดใหม่ — ใช้ payload ที่แลกได้');
    await _unmount(tester);
  });

  testWidgets('โหลดครั้งแรกล้มเหลว → ข้อความเป็นมิตร + ลองใหม่ได้', (tester) async {
    final api = _FakeKycApi()..status = const ApiErr('NETWORK', 'SocketException: boom');
    await _pump(tester, api: api);

    expect(find.text('โหลดสถานะยืนยันตัวตนไม่สำเร็จ'), findsOneWidget);
    expect(find.textContaining('ต่ออินเทอร์เน็ตไม่ได้'), findsOneWidget);
    expect(find.textContaining('SocketException'), findsNothing);

    api.status = ApiOk(_approved);
    await tester.tap(find.text('ลองใหม่'));
    await tester.pump();
    await tester.pump();
    expect(find.text('ยืนยันตัวตนแล้ว'), findsOneWidget);
    await _unmount(tester);
  });

  testWidgets('สลับกระเป๋าระหว่างเปิดหน้า → ทิ้งของกระเป๋าเก่า โหลดของใหม่',
      (tester) async {
    final api = _FakeKycApi()..status = ApiOk(_approved);
    final h = await _pump(tester, api: api);
    expect(find.text('ยืนยันตัวตนแล้ว'), findsOneWidget);

    api.status = ApiOk(_status());
    h.wallet.switchTo('0x1111111111111111111111111111111111111111');
    await tester.pump();
    await tester.pump();

    expect(api.statusCalls, 2);
    expect(find.text('ยืนยันตัวตนแล้ว'), findsNothing);
    expect(find.text('ยืนยันตัวตนด้วยบัญชี Thaiprompt'), findsOneWidget);
    await _unmount(tester);
  });

  testWidgets('ปิดหน้าระหว่างรอคำตอบ → ไม่ setState หลัง dispose', (tester) async {
    final api = _FakeKycApi()..holdStatus = Completer<ApiResult<KycStatus>>();
    await _pump(tester, api: api);

    await _unmount(tester);
    api.holdStatus!.complete(ApiOk(_approved));
    await tester.pump();
    expect(tester.takeException(), isNull);
  });
}

/// Developed by Xman Studio
