/// TPIX TRADE — สร้างบอท AI ในแอพต้องเปิด/ปิดแบบเดียวกับหน้าเว็บ
///
/// เจ้าของสั่ง: "ในแอพมือถือ สร้างบอทก็ต้องสร้างได้ เปิดให้สอดคล้องกับเว็บ"
/// ไฟล์นี้ตรึงกติกาที่ต้องตรงกับ `resources/js/Pages/AiTrade.vue` + `useAiBot.js`
/// และสัญญาของ `/api/v1/ai-bot/*`:
///   • ใครสร้างได้ (มีแพลน + โควตาเหลือ) และเหตุผลที่บอกผู้ใช้ต้องตรงกับเหตุจริง
///   • กลยุทธ์ไหนเลือกได้ (ปลดล็อกตามแพลน + เซิร์ฟเวอร์บอกว่าใช้ได้จริง)
///   • เทมเพลต: ชุดตั้งต้น · ป้ายที่ไฮไลต์ · รหัสที่ส่ง ต้องคิดจากค่าจริงเหมือนเว็บ
///   • payload ของคำขอสร้าง
///   • แคตตาล็อกเพิ่มชนิดช่องใหม่ (กลยุทธ์ `custom`) แอพต้องไม่พัง ไม่ส่งค่าครึ่งๆ กลางๆ
///   • สร้างเสร็จบอทต้องโผล่ทันที แม้การดึงสถานะรอบนั้นจะล้ม
///
/// Developed by Xman Studio
library;

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/models/ai_bot_models.dart';
import 'package:tpix_trade/providers/ai_bot_provider.dart';
import 'package:tpix_trade/services/api_result.dart';

import 'support/ai_bot_fixtures.dart';

AiBotCatalog _catalog() => AiBotCatalog.fromJson(aiBotCatalogJson());

AiBotDraft _blank(AiBotCatalog catalog, String code) => AiBotDraft.blank(
      catalog: catalog,
      strategy: catalog.strategy(code)!,
      name: 'บอท 1',
      pair: 'btc-usdt',
    );

Future<(AiBotProvider, FakeBotApi)> _provider({FakeBotApi? api}) async {
  final fake = api ?? FakeBotApi();
  final provider = AiBotProvider(api: fake)..bind(FakeBotWallet());
  await provider.bootstrap();
  return (provider, fake);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('ธงฟีเจอร์จากแคตตาล็อก', () {
    test('อ่าน live_execution และ credit_topup_ready (เดิมแอพทิ้งสองตัวนี้)', () {
      final f = _catalog().features;

      expect(f.liveTrading, isTrue);
      expect(f.liveExecution, isTrue);
      expect(f.creditTopup, isFalse);
      expect(f.creditTopupReady, isTrue);
      expect(f.salesOpen, isFalse);
    });

    test('แคตตาล็อกรุ่นเก่าที่ไม่มีธงใหม่ = ปิดไว้ก่อน ไม่เดาว่าเปิด', () {
      final f = AiBotFeatures.fromJson({'live_trading': true});

      expect(f.liveExecution, isFalse);
      expect(f.creditTopupReady, isFalse);
      expect(AiBotFeatures.closed.liveExecution, isFalse);
    });
  });

  group('กลยุทธ์ไหนสร้างบอทได้ (ด่านเดียวกับ isSelectable ของหน้าเว็บ)', () {
    const unlocked = ['grid', 'custom'];

    test('ปลดล็อก + ใช้ได้จริง = สร้างได้', () {
      expect(_catalog().strategy('grid')!.createBlock(unlocked), StrategyBlock.none);
    });

    test('แพลนยังไม่ปลดล็อก = ล็อกตามแพลน', () {
      expect(
        _catalog().strategy('momentum')!.createBlock(unlocked),
        StrategyBlock.planLocked,
      );
    });

    test('"เร็วๆ นี้" กับ "ถอดออกจากการขาย" แยกกันได้ และมีเหตุผลสองภาษา', () {
      final catalog = _catalog();
      final soon = catalog.strategy('arbitrage')!;
      final retired = catalog.strategy('scalping')!;

      expect(soon.createBlock(const ['arbitrage']), StrategyBlock.unavailable);
      expect(soon.comingSoon, isTrue);
      expect(soon.blockedReason(true), contains('เร็วๆ นี้'));
      expect(soon.blockedReason(false), contains('Coming soon'));

      expect(retired.createBlock(const ['scalping']), StrategyBlock.unavailable);
      expect(retired.comingSoon, isFalse);
      expect(retired.retired, isTrue);
      expect(retired.blockedReason(true), contains('ถอดออกจากการขาย'));
      expect(retired.blockedReason(false), contains('Retired'));
    });

    test('ยังใช้ไม่ได้ สำคัญกว่าเรื่องแพลน — อัปเกรดไปก็ยังใช้ไม่ได้', () {
      // ทั้งล็อกตามแพลนและยังไม่เปิด → ต้องบอกว่ายังไม่เปิด ไม่ใช่ชวนอัปเกรด
      expect(
        _catalog().strategy('arbitrage')!.createBlock(const <String>[]),
        StrategyBlock.unavailable,
      );
    });

    test('เซิร์ฟเวอร์รุ่นเก่าที่ไม่ส่ง available/coming_soon = ใช้ได้ ไม่ติดป้าย', () {
      final s = AiBotStrategy.fromJson({'code': 'dca', 'params': const []});

      expect(s.available, isTrue);
      expect(s.comingSoon, isFalse);
      expect(s.createBlock(const ['dca']), StrategyBlock.none);
    });
  });

  group('ชนิดช่องที่แอพไม่รู้จัก (กลยุทธ์ custom)', () {
    test('แคตตาล็อกที่มีช่องชนิดใหม่อ่านได้ ไม่พัง และกลยุทธ์นั้นเป็น "สร้างได้บนเว็บ"', () {
      final catalog = _catalog();
      final custom = catalog.strategy('custom')!;

      expect(custom.params.firstWhere((p) => p.key == 'rules').isSupported, isFalse);
      expect(custom.appConfigurable, isFalse);
      // ปลดล็อกแล้วและเซิร์ฟเวอร์บอกว่าใช้ได้ — แต่แอพสร้างให้ไม่ได้
      expect(custom.createBlock(const ['grid', 'custom']), StrategyBlock.webOnly);
      expect(catalog.strategy('grid')!.appConfigurable, isTrue);
    });

    test('ช่องชนิดใหม่ไม่ถูกวาด และไม่ถูกส่งเป็นค่าปริยายครึ่งๆ กลางๆ', () {
      final catalog = _catalog();
      final custom = catalog.strategy('custom')!;

      final specs = custom.formSpecs(catalog.commonParams).map((p) => p.key);
      expect(specs, isNot(contains('rules')));
      expect(specs, contains('cooldown_bars'));
      expect(custom.webOnlySpecs(catalog.commonParams).map((p) => p.key), ['rules']);

      expect(custom.defaultParams(catalog.commonParams).containsKey('rules'), isFalse);
      final payload = _blank(catalog, 'custom').toRequestJson();
      expect((payload['params'] as Map).containsKey('rules'), isFalse);
    });

    test('ตัวเลือกที่ไม่มีรายการให้เลือก และชนิดแปลกๆ = กรอกไม่ได้', () {
      expect(StrategyParam.fromJson({'key': 'a', 'type': 'select'}).isSupported, isFalse);
      expect(
        StrategyParam.fromJson({'key': 'a', 'type': 'select', 'options': ['x']}).isSupported,
        isTrue,
      );
      expect(StrategyParam.fromJson({'key': 'a', 'type': 'multiselect', 'options': ['x']}).isSupported, isFalse);
      expect(StrategyParam.fromJson({'key': 'a', 'type': 'json'}).isSupported, isFalse);
      expect(StrategyParam.fromJson({'key': 'a', 'type': 'number'}).isSupported, isTrue);
      expect(StrategyParam.fromJson({'key': 'a', 'type': 'bool'}).isSupported, isTrue);
      // options/option_labels รูปผิด (ไม่ใช่รายการ) ต้องไม่พัง
      expect(
        StrategyParam.fromJson({'key': 'a', 'type': 'select', 'options': {'x': 1}, 'option_labels': 'oops'}).isSupported,
        isFalse,
      );
    });

    test('สวิตช์ร่วมชนิดใหม่: กลยุทธ์อื่นยังสร้างได้ แอพไม่ส่งคีย์นั้น (เซิร์ฟเวอร์ใช้ค่าปริยายเอง)', () {
      final json = aiBotCatalogJson();
      (json['common_params'] as List).add({
        'key': 'copy_from',
        'type': 'master_ref',
        'default': null,
      });
      final catalog = AiBotCatalog.fromJson(json);
      final grid = catalog.strategy('grid')!;

      expect(grid.appConfigurable, isTrue);
      expect(grid.createBlock(const ['grid']), StrategyBlock.none);
      expect(grid.webOnlySpecs(catalog.commonParams).map((p) => p.key), ['copy_from']);
      final params = _blank(catalog, 'grid').toRequestJson()['params'] as Map;
      expect(params.containsKey('copy_from'), isFalse);
      expect(params.containsKey('news_mode'), isTrue);
    });

    test('แก้บอท custom ที่สร้างบนเว็บ: ชุดกฎผ่านกลับไปเท่าเดิมทุกตัวอักษร', () {
      final catalog = _catalog();
      final custom = catalog.strategy('custom')!;
      final rules = {
        'entry': [
          {'indicator': 'rsi', 'op': '<', 'value': 30},
        ],
        'exit': [
          {'indicator': 'rsi', 'op': '>', 'value': 70},
        ],
      };
      final bot = AiBot.fromJson(aiBotJson(
        strategy: 'custom',
        params: {'rules': rules, 'cooldown_bars': 3, 'news_mode': 'off'},
      ));

      // เปลี่ยนแค่ชื่อ — ค่าที่แอพกรอกไม่ได้ต้องไม่ถูกแตะ
      final draft = AiBotDraft.fromBot(bot).copyWith(name: 'ชื่อใหม่');
      final sent = jsonDecode(jsonEncode(draft.toRequestJson())) as Map<String, dynamic>;

      expect(sent['name'], 'ชื่อใหม่');
      expect((sent['params'] as Map)['rules'], rules);
      expect((sent['params'] as Map)['news_mode'], 'off');

      // กดเทมเพลต (ถ้าวันหนึ่งมี) ก็ยังไม่ทำให้ชุดกฎหาย
      final withTemplate = AiBotDraft.fromBot(bot).applyTemplate(
        StrategyTemplate.fromJson({'code': 'x', 'timeframe': '4h', 'params': {'cooldown_bars': 5}}),
        custom,
        catalog,
      );
      expect(withTemplate.params['rules'], rules);
      expect(withTemplate.params['cooldown_bars'], 5);
    });
  });

  group('เทมเพลต (กติกาเดียวกับ applyTemplate / activeTemplate ของหน้าเว็บ)', () {
    test('บอทใหม่เริ่มจากชุด "สมดุล" และส่งรหัสนั้นไปด้วย', () {
      final catalog = _catalog();
      final grid = catalog.strategy('grid')!;
      final draft = _blank(catalog, 'grid');

      expect(draft.timeframe, '4h');
      expect(draft.params['grid_levels'], 8);
      expect(draft.stopLossPct, 6);
      expect(draft.matchingTemplate(grid)?.code, 'balanced');
      expect(draft.withMatchedTemplate(grid).toRequestJson()['template'], 'balanced');
    });

    test('ไม่มีชุด "สมดุล" = เริ่มจากเทมเพลตใบแรก (เดิมแอพไม่ใช้เทมเพลตเลย)', () {
      final catalog = _catalog();
      final momentum = catalog.strategy('momentum')!;

      expect(momentum.startingTemplate?.code, 'slow');
      final draft = _blank(catalog, 'momentum');
      expect(draft.params['fast_ema'], 20);
      expect(draft.stopLossPct, 4);
      expect(draft.matchingTemplate(momentum)?.code, 'slow');
    });

    test('แก้ช่องใดช่องหนึ่งแล้วป้ายหลุดทันที — พารามิเตอร์ · กรอบเวลา · กรอบเสี่ยง', () {
      final catalog = _catalog();
      final grid = catalog.strategy('grid')!;
      final draft = _blank(catalog, 'grid');

      final paramEdited = draft.copyWith(params: {...draft.params, 'grid_levels': 9});
      expect(paramEdited.matchingTemplate(grid), isNull);

      final timeframeEdited = draft.copyWith(timeframe: '1h');
      expect(timeframeEdited.matchingTemplate(grid), isNull,
          reason: 'ทุกชุดที่ค่าตรงเป็นของแท่ง 4 ชม. — เปลี่ยนกรอบเวลาแล้วไม่ใช่ชุดนั้น');

      // เดิมแอพจำรหัสที่กดไว้: แก้ตัดขาดทุนแล้วป้าย "สมดุล" ยังค้าง และยังส่งรหัสไปด้วย
      final riskEdited = draft.copyWith(stopLossPct: 7);
      expect(riskEdited.matchingTemplate(grid), isNull);
      expect(riskEdited.template, 'balanced', reason: 'ค่าที่จำไว้ยังค้าง — ต้องใช้ค่าที่คิดใหม่');
      expect(
        riskEdited.withMatchedTemplate(grid).toRequestJson().containsKey('template'),
        isFalse,
      );
    });

    test('แก้กลับมาตรงชุดเดิม ป้ายกลับมา · ตัวเลข 8 กับ 8.0 กับ "8" คือค่าเดียวกัน', () {
      final catalog = _catalog();
      final grid = catalog.strategy('grid')!;
      final draft = _blank(catalog, 'grid');

      final typed = draft.copyWith(
        params: {...draft.params, 'grid_levels': 8.0, 'range_pct': '10', 'er_max': 0.35},
        stopLossPct: 6.0,
      );
      expect(typed.matchingTemplate(grid)?.code, 'balanced');

      final conservative = draft.copyWith(
        params: {...draft.params, 'grid_levels': 5, 'er_max': 0.3},
        stopLossPct: 8,
        takeProfitPct: 20,
      );
      expect(conservative.matchingTemplate(grid)?.code, 'conservative');
    });

    test('ช่องที่เทมเพลตไม่ได้ระบุ คงค่าที่ผู้ใช้ตั้งไว้ (เดิมแอพรีเซ็ตเป็นค่าปริยาย)', () {
      final catalog = _catalog();
      final grid = catalog.strategy('grid')!;
      final draft = _blank(catalog, 'grid');
      final edited = draft.copyWith(
        params: {...draft.params, 'er_max': 0.6, 'news_mode': 'off', 'macro_ema': '200'},
      );

      final applied = edited.applyTemplate(grid.template('aggressive')!, grid, catalog);

      expect(applied.timeframe, '1h');
      expect(applied.params['er_max'], 0.6, reason: 'ชุดนี้ไม่ระบุ er_max — เว็บคงค่าในฟอร์ม');
      expect(applied.params['news_mode'], 'off');
      expect(applied.params['macro_ema'], '200');
      expect(applied.stopLossPct, 5);
      expect(applied.matchingTemplate(grid)?.code, 'aggressive');
    });

    test('สลับกลยุทธ์ตอนแก้บอทเดิม: ไม่เอาชุดตั้งต้นมาทับกรอบเสี่ยงของบอท (เหมือนเว็บ)', () {
      final json = aiBotCatalogJson();
      final catalog = AiBotCatalog.fromJson(json);
      final momentum = catalog.strategy('momentum')!;
      final bot = AiBot.fromJson(aiBotJson());
      final draft = AiBotDraft.fromBot(bot).copyWith(stopLossPct: 9, timeframe: '1h');

      // บอทใหม่: ชุดตั้งต้นของกลยุทธ์ใหม่ทับทั้งกรอบเวลาและกรอบเสี่ยง
      final fresh = draft.withStrategy(momentum, catalog);
      expect(fresh.stopLossPct, 4);
      expect(fresh.timeframe, '4h');
      expect(fresh.params['fast_ema'], 20);

      // แก้บอทเดิม: กรอบเสี่ยงและกรอบเวลาที่ตั้งไว้ยังอยู่ ช่องของกลยุทธ์ใหม่ได้ค่าปริยาย
      final editing = draft.withStrategy(momentum, catalog, startFromTemplate: false);
      expect(editing.strategy, 'momentum');
      expect(editing.stopLossPct, 9);
      expect(editing.timeframe, '1h');
      expect(editing.params['fast_ema'], 12);
      expect(editing.params.containsKey('grid_levels'), isFalse);
      expect(editing.matchingTemplate(momentum), isNull);

      // กรอบเวลาที่กลยุทธ์ใหม่ไม่รองรับ = ตัวแรกของกลยุทธ์ใหม่ (ไม่งั้นโดน 422 ตอนบันทึก)
      final daily = draft.copyWith(timeframe: '1d').withStrategy(
            momentum,
            catalog,
            startFromTemplate: false,
          );
      expect(daily.timeframe, '1h');
    });

    test('เทมเพลตที่ไม่ระบุกรอบเวลา ไม่แตะกรอบเวลาของฟอร์ม', () {
      final catalog = _catalog();
      final grid = catalog.strategy('grid')!;
      final draft = _blank(catalog, 'grid').copyWith(timeframe: '1h');
      final noTf = StrategyTemplate.fromJson({'code': 'x', 'params': {'grid_levels': 12}});

      final applied = draft.applyTemplate(noTf, grid, catalog);

      expect(applied.timeframe, '1h');
      expect(applied.params['grid_levels'], 12);
    });

    test('คำเตือนของชุดที่ขาดทุนมาครบสองภาษา', () {
      final t = _catalog().strategy('grid')!.template('conservative')!;

      expect(t.tagline(true), contains('ไม่ชนะต้นทุนเข้า-ออก'));
      expect(t.tagline(false), contains('does not beat trading costs'));
    });
  });

  group('payload ของคำขอสร้างบอท (POST /api/v1/ai-bot/bots)', () {
    test('คีย์และชนิดตรงกับที่หน้าเว็บส่ง', () {
      final catalog = _catalog();
      final grid = catalog.strategy('grid')!;
      final payload = _blank(catalog, 'grid').withMatchedTemplate(grid).toRequestJson();

      expect(payload.keys.toSet(), {'name', 'pair', 'strategy', 'timeframe', 'template', 'params', 'risk'});
      expect(payload['name'], 'บอท 1');
      expect(payload['pair'], 'BTC/USDT', reason: 'เซิร์ฟเวอร์รับเฉพาะรูป BASE/QUOTE');
      expect(payload['strategy'], 'grid');
      expect(payload['timeframe'], '4h');

      // ครบทุกช่องของกลยุทธ์ + สวิตช์ร่วม — ไม่มีช่องไหนถูกปล่อยให้เซิร์ฟเวอร์เดา
      expect((payload['params'] as Map).keys.toSet(), {
        'news_mode', 'auto_pair', 'ai_gate', 'macro_filter', 'macro_ema',
        'grid_levels', 'range_pct', 'regime_filter', 'er_max',
      });
      expect((payload['params'] as Map)['macro_ema'], '50');
      expect((payload['params'] as Map)['news_mode'], 'confirm_exit');

      final risk = payload['risk'] as Map;
      expect(risk['max_position_usd'], 100);
      expect(risk['max_position_usd_requested'], risk['max_position_usd'],
          reason: 'เซิร์ฟเวอร์ใช้ค่า requested ก่อน — ต้องส่งให้เท่ากับที่กรอก');
      expect(risk['stop_loss_pct'], 6);
      expect(risk['take_profit_pct'], 15);
      expect(risk['max_daily_loss_usd'], 50);
    });

    test('ป้ายตัวเลือกสองภาษา — ไม่มีป้ายก็แสดงค่าดิบ', () {
      final news = _catalog().commonParams.firstWhere((p) => p.key == 'news_mode');

      expect(news.optionLabel('off', true), 'ไม่ใช้ข่าว');
      expect(news.optionLabel('off', false), 'Ignore news');
      expect(news.optionLabel('block_entries', true), 'block_entries');
    });
  });

  group('คู่เทรดในฟอร์ม (ชุดเดียวกับ /market/pairs ที่หน้าเว็บใช้)', () {
    test('ใช้ทะเบียนคู่ของแอดมินก่อน ตามลำดับเดิม ไม่ซ้ำ', () {
      final pairs = aiBotPairOptions(
        [('BTC', 'USDT'), ('eth', 'usdt'), ('BTC', 'USDT'), ('TPIX', 'USDT')],
        [('DOGE', 'USDT')],
      );

      expect(pairs, ['BTC/USDT', 'ETH/USDT', 'TPIX/USDT']);
    });

    test('ทะเบียนยังไม่มา = ใช้รายการสำรอง · ว่างทั้งคู่ = BTC/USDT', () {
      expect(aiBotPairOptions(const [], [('SOL', 'USDT')]), ['SOL/USDT']);
      expect(aiBotPairOptions(const []), ['BTC/USDT']);
    });

    test('คู่ที่รูปแบบไม่ผ่านด่านของเซิร์ฟเวอร์ไม่ถูกเสนอ', () {
      expect(
        aiBotPairOptions([('B', 'USDT'), ('BTC', ''), ('BT-C', 'USDT'), ('XRP', 'USDT')]),
        ['XRP/USDT'],
      );
    });
  });

  group('บอทหลังสร้าง', () {
    test('เหตุผลที่แพลนไม่ให้เดิน: บอทที่ระบบพักไว้ และบอทที่เปิดอยู่แต่แพลนไม่ให้เดิน', () {
      final paused = AiBot.fromJson(aiBotJson(extra: {'pause_reason': 'plan_expired'}));
      expect(paused.planReasonCode, 'plan_expired');
      expect(paused.planReasonText(true), contains('หมดอายุ'));
      expect(paused.planReasonText(false), contains('expired'));

      final running = AiBot.fromJson(aiBotJson(
        status: 'running',
        extra: {'online': false, 'offline_reason': 'plan_locked'},
      ));
      expect(running.planReasonCode, 'plan_locked');
      expect(running.offlineText(false), contains('does not include this strategy'));

      // วอร์กเกอร์เงียบไม่ใช่เรื่องของแพลน — ข้อความเดิมยังใช้
      final silent = AiBot.fromJson(aiBotJson(
        status: 'running',
        extra: {'online': false, 'offline_reason': 'worker_silent'},
      ));
      expect(silent.planReasonCode, isNull);
      expect(silent.offlineText(true), contains('วอร์กเกอร์เงียบ'));

      // ผู้ใช้กดพักเอง = ไม่มีเหตุผลของแพลน
      expect(AiBot.fromJson(aiBotJson()).planReasonCode, isNull);
    });

    test('live_supported: อ่านได้ และไม่มีคีย์ = ไม่รู้ (ไม่ใช่ false)', () {
      expect(AiBot.fromJson(aiBotJson(extra: {'live_supported': false})).liveSupported, isFalse);
      expect(AiBot.fromJson(aiBotJson(extra: {'live_supported': true})).liveSupported, isTrue);
      expect(AiBot.fromJson(aiBotJson()).liveSupported, isNull);
    });

    test('withNewBot: บอทใหม่อยู่หัวรายการ นับโควตาเพิ่ม และไม่ซ้ำเมื่อมีอยู่แล้ว', () {
      final status = AiBotStatus.fromJson(aiBotStatusJson(bots: [aiBotJson(id: 1)]));
      final created = AiBot.fromJson(aiBotJson(id: 2));

      final next = status.withNewBot(created);
      expect(next.bots.map((b) => b.id), [2, 1]);
      expect(next.quota.usedBots, 2);
      expect(next.quota.maxBots, 3);

      final again = next.withNewBot(created);
      expect(again.bots.map((b) => b.id), [2, 1]);
      expect(again.quota.usedBots, 2);
    });
  });

  group('AiBotProvider — ใครสร้างบอทได้', () {
    test('มีแพลน + โควตาเหลือ = สร้างได้', () async {
      final (provider, _) = await _provider();

      expect(provider.createBlock, AiBotCreateBlock.none);
      expect(provider.canCreateBot, isTrue);
    });

    test('โควตาเต็ม = บอกให้ "หยุด" บอทตัวเก่า', () async {
      final api = FakeBotApi()
        ..status = ApiOk(AiBotStatus.fromJson(
          aiBotStatusJson(maxBots: 1, bots: [aiBotJson(id: 1)]),
        ));
      final (provider, _) = await _provider(api: api);

      expect(provider.createBlock, AiBotCreateBlock.quotaFull);
      expect(provider.canCreateBot, isFalse);
      expect(provider.createBlock.text(true), contains('หยุด'));
      expect(provider.createBlock.text(false), contains('Stop'));
    });

    test('ยังไม่มีแพลน (โควตา 0/0) = บอกให้เลือกแพลน ไม่ใช่ "โควตาเต็ม"', () async {
      final api = FakeBotApi()
        ..status = ApiOk(AiBotStatus.fromJson(aiBotStatusJson(withPlan: false)));
      final (provider, _) = await _provider(api: api);

      expect(provider.createBlock, AiBotCreateBlock.noPlan);
      expect(provider.createBlock.text(true), contains('แพลน'));
      expect(provider.createBlock.text(true), isNot(contains('หยุด')));
    });

    test('ยังโหลดสถานะไม่ได้ = บอกให้รีเฟรช ไม่ใช่ "โควตาเต็ม"', () async {
      final api = FakeBotApi()
        ..status = const ApiErr('NETWORK', 'ต่ออินเทอร์เน็ตไม่ได้');
      final (provider, _) = await _provider(api: api);

      expect(provider.createBlock, AiBotCreateBlock.statusUnavailable);
      expect(provider.createBlock.text(false), contains('refresh'));
    });

    test('เติมเครดิต: ทีมงานใช้รางที่พร้อมแล้วได้ก่อนเปิดขาย (กติกาเดียวกับเว็บ)', () async {
      final (visitor, _) = await _provider();
      expect(visitor.topupEnabled, isFalse);

      final json = aiBotStatusJson()..['is_admin'] = true;
      final api = FakeBotApi()..status = ApiOk(AiBotStatus.fromJson(json));
      final (team, _) = await _provider(api: api);
      expect(team.topupEnabled, isTrue);
      expect(team.liveExecution, isTrue);
    });
  });

  group('AiBotProvider — สร้างบอท', () {
    test('สร้างสำเร็จ: ส่ง draft ไปตามที่กรอก และบอทโผล่ทันทีโดยไม่ต้องรีเฟรชเอง', () async {
      final api = FakeBotApi()
        ..statusAfterCreate = ApiOk(AiBotStatus.fromJson(
          aiBotStatusJson(bots: [aiBotJson(id: 42)]),
        ));
      final (provider, _) = await _provider(api: api);
      expect(provider.bots, isEmpty);

      final catalog = provider.catalog!;
      final saved = await provider.createBot(_blank(catalog, 'grid'));

      expect(saved?.id, 42);
      expect(api.drafts.single.strategy, 'grid');
      expect(provider.bots.map((b) => b.id), [42]);
      expect(provider.quota.usedBots, 1);
      expect(provider.hasError, isFalse);
    });

    test('สร้างสำเร็จแต่ดึงสถานะรอบนั้นล้ม: บอทยังต้องโผล่ และโควตาต้องนับ', () async {
      final api = FakeBotApi()
        ..statusAfterCreate = const ApiErr(
          'RATE_LIMITED',
          'ส่งคำขอถี่เกินไป',
          status: 429,
          retryAfterSeconds: 30,
        );
      final (provider, _) = await _provider(api: api);

      final saved = await provider.createBot(_blank(provider.catalog!, 'grid'));

      expect(saved?.id, 42, reason: 'เซิร์ฟเวอร์สร้างให้แล้ว');
      expect(provider.bots.map((b) => b.id), [42],
          reason: 'เดิมบอทไม่โผล่จนกว่าการดึงสถานะรอบถัดไปจะผ่าน');
      expect(provider.quota.usedBots, 1);
    });

    test('เซิร์ฟเวอร์ปฏิเสธด้วยรหัส: ข้อความสองภาษา ไม่มีข้อความดิบ และไม่มีบอทผี', () async {
      final api = FakeBotApi()
        ..created = const ApiErr(
          'BOT_LIMIT_REACHED',
          'จำนวนบอทเต็มโควตาของแพลนนี้แล้ว',
          status: 403,
        );
      final (provider, _) = await _provider(api: api);

      final saved = await provider.createBot(_blank(provider.catalog!, 'grid'));

      expect(saved, isNull);
      expect(provider.bots, isEmpty);
      expect(provider.errorCode, 'BOT_LIMIT_REACHED');
      expect(provider.errorText(true), contains('โควตา'));
      expect(provider.errorText(false), contains('quota'));
    });

    test('422 รายช่อง: โชว์เหตุผลจริงของเซิร์ฟเวอร์ ไม่ใช่ "ตรวจช่องสีแดง" ลอยๆ', () async {
      const reason = 'กลยุทธ์นี้ยังเปิดใช้งานไม่ได้ — เร็วๆ นี้';
      final api = FakeBotApi()
        ..created = const ApiErr(
          'VALIDATION_ERROR',
          reason,
          status: 422,
          fieldErrors: {
            'strategy': [reason],
          },
        );
      final (provider, _) = await _provider(api: api);

      final saved = await provider.createBot(_blank(provider.catalog!, 'grid'));

      expect(saved, isNull);
      expect(provider.fieldErrors['strategy'], [reason]);
      expect(provider.firstFieldError, reason);
      expect(provider.errorText(true), reason);
      expect(provider.errorText(false), reason);
    });

    test('รหัสที่แอพยังไม่รู้จัก: ใช้ข้อความที่เซิร์ฟเวอร์แปลมาให้ ไม่ใช่รหัสดิบ', () async {
      final api = FakeBotApi()
        ..created = const ApiErr(
          'TEMPLATE_NOT_FOUND',
          'ไม่พบเทมเพลตนี้ของกลยุทธ์ที่เลือก',
          status: 422,
        );
      final (provider, _) = await _provider(api: api);

      await provider.createBot(_blank(provider.catalog!, 'grid'));

      expect(provider.errorText(true), 'ไม่พบเทมเพลตนี้ของกลยุทธ์ที่เลือก');
      expect(provider.errorText(true), isNot(contains('TEMPLATE_NOT_FOUND')));
    });
  });
}
