/// TPIX TRADE — ชีตสร้าง/แก้บอท AI: วาดจริงแล้วกดจริงด้วย API ปลอม
///
/// ตรึงสิ่งที่ผู้ใช้เห็นและสิ่งที่ถูกส่งขึ้นเซิร์ฟเวอร์ ให้ตรงกับฟอร์มบนหน้าเว็บ:
///   • กลยุทธ์ที่เลือกไม่ได้ติดป้ายเหตุผล และแตะแล้วบอกว่าทำไม (ไม่ใช่ชิปเทาที่เงียบ)
///   • กลยุทธ์ที่แอพตั้งค่าไม่ครบขึ้น "สร้างได้บนเว็บ" เลือกไม่ได้ และไม่มีวันถูกส่ง
///   • คำเตือนของเทมเพลตที่ขาดทุนเห็นก่อนเลือก · รหัสเทมเพลตที่ส่งคิดจากค่าจริง
///   • เซิร์ฟเวอร์ปฏิเสธแล้วฟอร์มอยู่ต่อพร้อมเหตุผลจริง ไม่มีข้อความดิบ ไม่ล้มเงียบ
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/locale/locale_provider.dart';
import 'package:tpix_trade/models/ai_bot_models.dart';
import 'package:tpix_trade/providers/accent_provider.dart';
import 'package:tpix_trade/providers/ai_bot_provider.dart';
import 'package:tpix_trade/screens/ai_trade/ai_bot_screen.dart';
import 'package:tpix_trade/services/api_result.dart';
import 'package:tpix_trade/widgets/common/gradient_button.dart';

import 'support/ai_bot_fixtures.dart';

class _Harness {
  final FakeBotApi api;
  final AiBotProvider provider;

  /// ผลที่ชีตคืนตอนปิด (บอทที่บันทึกแล้ว) — null = ยังไม่ปิด/ปิดโดยไม่บันทึก
  AiBot? result;
  bool closed = false;

  _Harness(this.api, this.provider);
}

/// เปิดชีตแบบเดียวกับหน้าจริง (showModalBottomSheet) — onSave ต่อเข้า provider จริง
Future<_Harness> _open(
  WidgetTester tester, {
  FakeBotApi? api,
  String lang = 'th',
  List<String> unlocked = const ['grid', 'custom'],
  AiBot? existing,
}) async {
  // จอสูง — ให้ทุกช่องของฟอร์มอยู่ในจอ ไม่ต้องเลื่อนหาก่อนกด
  tester.view.physicalSize = const Size(900, 3600);
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

  final fake = api ?? FakeBotApi();
  final provider = AiBotProvider(api: fake)..bind(FakeBotWallet());
  await provider.bootstrap();
  final h = _Harness(fake, provider);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<LocaleProvider>.value(value: locale),
        ChangeNotifierProvider<AccentProvider>.value(value: accent),
        ChangeNotifierProvider<AiBotProvider>.value(value: provider),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => Center(
              child: TextButton(
                onPressed: () async {
                  h.result = await showModalBottomSheet<AiBot>(
                    context: context,
                    isScrollControlled: true,
                    backgroundColor: Colors.transparent,
                    builder: (_) => debugBotEditorSheet(
                      catalog: provider.catalog!,
                      unlocked: unlocked,
                      existing: existing,
                      pairs: const ['BTC/USDT', 'ETH/USDT'],
                      defaultName: 'บอท 1',
                      onSave: (draft) => existing == null
                          ? provider.createBot(draft)
                          : provider.updateBot(existing.id, draft),
                    ),
                  );
                  h.closed = true;
                },
                child: const Text('open'),
              ),
            ),
          ),
        ),
      ),
    ),
  );

  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
  return h;
}

Future<void> _submit(WidgetTester tester, String label) async {
  final button = find.widgetWithText(GradientButton, label);
  await tester.ensureVisible(button);
  await tester.tap(button);
  await tester.pumpAndSettle();
}

/// ช่องตัวเลขของกรอบความเสี่ยงตามลำดับในฟอร์ม (ช่องแรกสุดของฟอร์มคือชื่อบอท)
Finder _riskField(int index) => find.byType(TextField).at(1 + index);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('ชิปกลยุทธ์ติดป้ายเหตุผลครบทุกแบบ และบอทใหม่เริ่มที่กลยุทธ์แรกที่สร้างได้',
      (tester) async {
    // หลังสร้าง เซิร์ฟเวอร์ตอบสถานะที่มีบอทตัวใหม่แล้ว
    final api = FakeBotApi()
      ..statusAfterCreate = ApiOk(AiBotStatus.fromJson(
        aiBotStatusJson(bots: [aiBotJson(id: 42)]),
      ));
    final h = await _open(tester, api: api);

    // ป้ายสั้นบนชิป — แยก "เร็วๆ นี้" กับ "ยังไม่เปิด" ออกจากกันเหมือนหน้าเว็บ
    expect(find.text('Basic ขึ้นไป'), findsOneWidget, reason: 'momentum ล็อกตามแพลน');
    expect(find.text('ยังไม่เปิด'), findsOneWidget, reason: 'scalping ถอดออกจากการขาย');
    expect(find.text('เร็วๆ นี้'), findsOneWidget, reason: 'arbitrage ยังไม่มีตัวลงมือจริง');
    expect(find.text('สร้างได้บนเว็บ'), findsOneWidget, reason: 'custom มีช่องที่แอพไม่รู้จัก');

    await _submit(tester, 'สร้างบอท');

    expect(h.api.drafts.single.strategy, 'grid');
    expect(h.closed, isTrue, reason: 'สร้างสำเร็จ ชีตปิดเอง');
    expect(h.result?.id, 42);
    expect(h.provider.bots.map((b) => b.id), contains(42));
  });

  testWidgets('แตะกลยุทธ์ที่เลือกไม่ได้: บอกเหตุผล ไม่สลับกลยุทธ์ และไม่ถูกส่ง', (tester) async {
    final h = await _open(tester);

    // สร้างได้บนเว็บ (custom)
    await tester.tap(find.text('กฎกำหนดเอง'));
    await tester.pumpAndSettle();
    expect(find.textContaining('กลยุทธ์นี้สร้างได้บนเว็บ'), findsOneWidget);

    // เร็วๆ นี้ — ใช้เหตุผลที่เซิร์ฟเวอร์ส่งมา
    await tester.tap(find.text('Spread Arbitrage'));
    await tester.pumpAndSettle();
    expect(find.textContaining('กลยุทธ์นี้อยู่ระหว่างพัฒนา'), findsOneWidget);
    expect(find.textContaining('กลยุทธ์นี้สร้างได้บนเว็บ'), findsNothing);

    // ถอดออกจากการขาย
    await tester.tap(find.text('Micro Scalper'));
    await tester.pumpAndSettle();
    expect(find.textContaining('ขาดทุนโดยโครงสร้าง'), findsOneWidget);

    // ล็อกตามแพลน
    await tester.tap(find.text('Momentum Trend'));
    await tester.pumpAndSettle();
    expect(find.textContaining('ต้องใช้แพลนระดับ Basic ขึ้นไป'), findsOneWidget);

    await _submit(tester, 'สร้างบอท');

    expect(h.api.drafts.single.strategy, 'grid',
        reason: 'แตะชิปที่เลือกไม่ได้ต้องไม่เปลี่ยนกลยุทธ์ของฟอร์ม');
  });

  testWidgets('ป้ายและเหตุผลภาษาอังกฤษ', (tester) async {
    await _open(tester, lang: 'en');

    expect(find.text('WEB ONLY'), findsOneWidget);
    expect(find.text('SOON'), findsOneWidget);
    expect(find.text('NOT LIVE'), findsOneWidget);
    expect(find.text('Basic and up'), findsOneWidget);

    await tester.tap(find.text('Custom rules'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Create this one on the web'), findsOneWidget);

    await tester.tap(find.text('Micro Scalper'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Retired — it loses by construction.'), findsOneWidget);
  });

  testWidgets('เทมเพลต: คำเตือนของชุดที่ขาดทุนเห็นก่อนเลือก และส่งรหัสของชุดที่ค่าตรงจริง',
      (tester) async {
    final h = await _open(tester);

    // ชุด conservative ยังไม่ได้ถูกเลือก แต่คำเตือนของมันต้องอยู่บนจอแล้ว
    expect(find.textContaining('ไม่ชนะต้นทุนเข้า-ออก'), findsOneWidget);
    expect(find.textContaining('กำหนดเอง — ค่าที่ตั้งอยู่ไม่ตรงกับเทมเพลตใด'), findsNothing);

    await _submit(tester, 'สร้างบอท');

    final draft = h.api.drafts.single;
    expect(draft.template, 'balanced');
    expect(draft.toRequestJson()['template'], 'balanced');
    expect(draft.stopLossPct, 6);
    expect(draft.timeframe, '4h');
  });

  testWidgets('แก้ตัวเลขความเสี่ยงแล้วป้ายเทมเพลตหลุด และไม่ส่งรหัสเทมเพลต', (tester) async {
    final h = await _open(tester);

    // ช่องที่ 2 ของกรอบความเสี่ยง = ตัดขาดทุน (ชุดสมดุลตั้งไว้ 6)
    await tester.enterText(_riskField(1), '7');
    await tester.pumpAndSettle();
    expect(find.textContaining('กำหนดเอง — ค่าที่ตั้งอยู่ไม่ตรงกับเทมเพลตใด'), findsOneWidget,
        reason: 'เดิมป้าย "สมดุล" กับผล backtest ของมันยังค้างทั้งที่ค่าไม่ใช่ชุดนั้นแล้ว');

    await _submit(tester, 'สร้างบอท');

    final draft = h.api.drafts.single;
    expect(draft.stopLossPct, 7);
    expect(draft.template, isNull);
    expect(draft.toRequestJson().containsKey('template'), isFalse);
  });

  testWidgets('กดเทมเพลตอื่น: ค่าทั้งชุดเปลี่ยนตาม และส่งรหัสของชุดนั้น', (tester) async {
    final h = await _open(tester);

    await tester.tap(find.text('กรอบกว้าง เก็บช้า'));
    await tester.pumpAndSettle();
    await _submit(tester, 'สร้างบอท');

    final draft = h.api.drafts.single;
    expect(draft.template, 'conservative');
    expect(draft.params['grid_levels'], 5);
    expect(draft.stopLossPct, 8);
    expect(draft.takeProfitPct, 20);
  });

  testWidgets('เซิร์ฟเวอร์ตอบ 422 ที่ช่องกลยุทธ์: ฟอร์มอยู่ต่อ ค่าที่กรอกไม่หาย และเห็นเหตุผลจริง',
      (tester) async {
    const reason = 'กลยุทธ์นี้ยังเปิดใช้งานไม่ได้ — ถอดออกจากการขายแล้ว';
    final api = FakeBotApi()
      ..created = const ApiErr(
        'VALIDATION_ERROR',
        reason,
        status: 422,
        fieldErrors: {
          'strategy': [reason],
        },
      );
    final h = await _open(tester, api: api);

    await tester.enterText(find.byType(TextField).first, 'กริดกลางคืน');
    await _submit(tester, 'สร้างบอท');

    expect(h.closed, isFalse, reason: 'ถูกปฏิเสธ ชีตต้องอยู่ต่อ');
    // ใต้ช่องกลยุทธ์ + สรุปท้ายฟอร์ม
    expect(find.text(reason), findsNWidgets(2));
    expect(find.textContaining('ตรวจช่องที่มีข้อความสีแดง'), findsNothing,
        reason: 'เดิมบอกให้ดูช่องสีแดงที่ไม่มีอยู่จริง');
    expect(find.text('กริดกลางคืน'), findsOneWidget);
    expect(h.provider.bots, isEmpty);
  });

  testWidgets('เซิร์ฟเวอร์ตอบรหัสปฏิเสธ (โควตาเต็ม): ข้อความของแอพตามภาษา ไม่ใช่รหัสดิบ',
      (tester) async {
    final api = FakeBotApi()
      ..created = const ApiErr(
        'BOT_LIMIT_REACHED',
        'จำนวนบอทเต็มโควตาของแพลนนี้แล้ว',
        status: 403,
      );
    final h = await _open(tester, api: api, lang: 'en');

    await _submit(tester, 'Create bot');

    expect(h.closed, isFalse);
    expect(find.textContaining('Your plan bot quota is full'), findsOneWidget);
    expect(find.textContaining('BOT_LIMIT_REACHED'), findsNothing);
  });

  testWidgets('ต้องยืนยันตัวตน: มีปุ่มพาไปยืนยันอยู่ใต้ข้อความ', (tester) async {
    final api = FakeBotApi()
      ..created = const ApiErr('KYC_REQUIRED', 'ต้องยืนยันตัวตนก่อน', status: 403);
    final h = await _open(tester, api: api);

    await _submit(tester, 'สร้างบอท');

    expect(h.closed, isFalse);
    expect(find.widgetWithText(GradientButton, 'ไปยืนยันตัวตน'), findsOneWidget);
  });

  testWidgets('ไม่มีกลยุทธ์ไหนสร้างจากแอพได้: กดสร้างแล้วไม่ยิงคำขอ และบอกเหตุผล',
      (tester) async {
    // แคตตาล็อกที่เหลือแต่กลยุทธ์ custom — ฟอร์มเปิดมาพร้อมตัวแรกของรายการเสมอ
    final json = aiBotCatalogJson();
    json['strategies'] = (json['strategies'] as List)
        .where((s) => (s as Map)['code'] == 'custom')
        .toList();
    final api = FakeBotApi()..catalogJson = json;
    final h = await _open(tester, api: api, unlocked: const ['custom']);

    await _submit(tester, 'สร้างบอท');

    expect(h.api.drafts, isEmpty, reason: 'ห้ามส่งบอทที่ชุดกฎว่างขึ้นไป');
    expect(h.closed, isFalse);
    expect(find.textContaining('กลยุทธ์นี้สร้างได้บนเว็บ'), findsWidgets);
  });

  testWidgets('แก้บอท custom ที่สร้างบนเว็บ: เปลี่ยนชื่อได้ ชุดกฎไม่ถูกส่ง (เซิร์ฟเวอร์เก็บชุดเดิม) และบอกว่าช่องไหนแก้ไม่ได้',
      (tester) async {
    final rules = {
      'entry': [
        {'indicator': 'rsi', 'op': '<', 'value': 30},
      ],
      'exit': <dynamic>[],
    };
    final existing = AiBot.fromJson(aiBotJson(
      id: 9,
      strategy: 'custom',
      params: {'rules': rules, 'cooldown_bars': 3, 'news_mode': 'off'},
    ));
    final api = _UpdatingApi();
    final h = await _open(tester, api: api, existing: existing);

    expect(find.textContaining('มีบางช่องที่แอพรุ่นนี้ยังตั้งไม่ได้'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'กฎของฉัน');
    await _submit(tester, 'บันทึกการแก้ไข');

    final draft = api.updated.single;
    expect(draft.name, 'กฎของฉัน');
    expect(draft.strategy, 'custom');
    // ชุดกฎไม่ถูกส่งกลับ: เซิร์ฟเวอร์เก็บชุดที่บันทึกไว้เมื่อคำขอแก้บอทไม่มี `rules`
    // (ส่งกลับไปชุดที่ถูกติดธง invalid จะโดนปฏิเสธทั้งคำขอ — ดู ai_bot_custom_web_only_test.dart)
    expect(draft.params.containsKey('rules'), isFalse);
    expect(draft.template, isNull);
    expect(draft.params['news_mode'], 'off');
    expect(h.closed, isTrue);
  });
}

/// API ปลอมที่รับคำขอแก้บอทด้วย — จำ draft ที่ถูกส่งมา
class _UpdatingApi extends FakeBotApi {
  final List<AiBotDraft> updated = [];

  @override
  Future<ApiResult<AiBot>> updateBot({
    required String wallet,
    required int botId,
    required AiBotDraft draft,
  }) async {
    updated.add(draft);
    return ApiOk(AiBot.fromJson(aiBotJson(
      id: botId,
      strategy: draft.strategy,
      params: draft.params,
    )));
  }
}
