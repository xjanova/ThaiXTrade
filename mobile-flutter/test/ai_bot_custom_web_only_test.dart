/// TPIX TRADE — กลยุทธ์ "วางกลยุทธ์เอง" (`custom`) ในแอพ: สร้างได้บนเว็บเท่านั้น
///
/// ใช้แคตตาล็อกจริงจาก production (`test/support/live_catalog_custom.json` — ตัดมาจาก
/// GET /api/v1/ai-bot/catalog เมื่อ 2026-11-14 เหลือ `grid` กับ `custom`) ไม่ใช่รูปที่เดาไว้
/// ก่อนกลยุทธ์นี้จะขึ้นจริง — สัญญาที่ต้องตรงกับเซิร์ฟเวอร์:
///   • `custom` มีช่องชนิด `rules` ที่แอพรุ่นนี้วาดไม่ได้ → ขึ้น "สร้างได้บนเว็บ" เลือกสร้างไม่ได้
///   • เทมเพลตของ `custom` พกชุดกฎมาทั้งชุด → แอพต้องไม่เอาชุดกฎจากเทมเพลตมาทับของผู้ใช้
///     และไม่ส่งรหัสเทมเพลต (เซิร์ฟเวอร์ใช้เทมเพลตเป็นฐานของคำขอ)
///   • แก้บอท custom ที่สร้างบนเว็บ: ไม่ส่ง `rules` — `AiBotController::update` เก็บชุดเดิมให้
///     ส่งชุดที่ถูกติดธง `invalid` กลับไปจะโดน `RuleSet::validate` ปฏิเสธทั้งคำขอ
///     (ผู้ใช้จะแก้ชื่อ/กรอบความเสี่ยงของบอทตัวนั้นจากแอพไม่ได้เลย)
///
/// Developed by Xman Studio
library;

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/ai_bot_models.dart';

import 'support/ai_bot_fixtures.dart';

AiBotCatalog _liveCatalog() => AiBotCatalog.fromJson(
      jsonDecode(File('test/support/live_catalog_custom.json').readAsStringSync()) as Map<String, dynamic>,
    );

/// ชุดกฎรูปเดียวกับที่เซิร์ฟเวอร์เก็บ (entry/exit · match · blocks)
Map<String, dynamic> _rules({bool invalid = false}) => {
      'entry': {
        'match': 'all',
        'blocks': [
          {'type': 'rsi', 'when': 'below', 'period': 14, 'value': 30},
        ],
      },
      'exit': {'match': 'any', 'blocks': <dynamic>[]},
      if (invalid) 'invalid': true,
    };

AiBot _customBot(Map<String, dynamic> rules) => AiBot.fromJson(aiBotJson(
      id: 11,
      strategy: 'custom',
      params: {
        'rules': rules,
        'order_size_usd': 40,
        'add_to_position': false,
        'trailing_stop_pct': 0,
        'max_hold_bars': 0,
        'time_stop_mode': 'no_profit',
        'cooldown_bars': 3,
        'news_mode': 'off',
      },
    ));

void main() {
  group('แคตตาล็อกจริง: custom', () {
    test('ช่อง rules เป็นชนิดที่แอพไม่รู้จัก — กลยุทธ์ขึ้น "สร้างได้บนเว็บ" แม้แพลนปลดล็อกแล้ว', () {
      final catalog = _liveCatalog();
      final custom = catalog.strategy('custom')!;

      expect(custom.available, isTrue, reason: 'เซิร์ฟเวอร์เปิดกลยุทธ์นี้แล้ว');
      final rules = custom.params.firstWhere((p) => p.key == 'rules');
      expect(rules.type, 'rules');
      expect(rules.isSupported, isFalse);
      expect(rules.keptByServerWhenOmitted, isTrue);

      expect(custom.appConfigurable, isFalse);
      expect(custom.createBlock(const ['grid', 'custom']), StrategyBlock.webOnly);
      // กลยุทธ์ปกติไม่ถูกกระทบ
      expect(catalog.strategy('grid')!.createBlock(const ['grid', 'custom']), StrategyBlock.none);
    });

    test('ฟอร์มวาดเฉพาะช่องที่แอพกรอกได้ และค่าตั้งต้นไม่มีชุดกฎเปล่าติดไป', () {
      final catalog = _liveCatalog();
      final custom = catalog.strategy('custom')!;

      final drawn = custom.formSpecs(catalog.commonParams).map((p) => p.key).toList();
      expect(drawn, isNot(contains('rules')));
      expect(drawn, containsAll(['order_size_usd', 'add_to_position', 'time_stop_mode', 'cooldown_bars', 'news_mode']));
      expect(custom.webOnlySpecs(catalog.commonParams).map((p) => p.key), ['rules']);
      expect(custom.defaultParams(catalog.commonParams).containsKey('rules'), isFalse);
    });

    test('เทมเพลตของ custom พกชุดกฎมาจริง (เหตุผลที่แอพต้องไม่ใช้มัน)', () {
      final custom = _liveCatalog().strategy('custom')!;

      expect(custom.templates, isNotEmpty);
      for (final t in custom.templates) {
        expect(t.params['rules'], isA<Map>(), reason: 'เทมเพลต ${t.code}');
      }
    });
  });

  group('แก้บอท custom ที่สร้างบนเว็บ', () {
    test('แตะเทมเพลตไม่เปลี่ยนชุดกฎของผู้ใช้ และไม่มีรหัสเทมเพลตถูกส่ง', () {
      final catalog = _liveCatalog();
      final custom = catalog.strategy('custom')!;
      final mine = _rules();
      final draft = AiBotDraft.fromBot(_customBot(mine));

      for (final t in custom.templates) {
        final applied = draft.applyTemplate(t, custom, catalog);

        expect(applied.params['rules'], mine, reason: 'เทมเพลต ${t.code} ทับชุดกฎที่มองไม่เห็นในแอพ');
        // ค่าที่แอพกรอกได้ยังเปลี่ยนตามเทมเพลตเหมือนกลยุทธ์อื่น
        expect(applied.params['order_size_usd'], t.params['order_size_usd']);
        // ไม่ผูกกับเทมเพลตใด — ส่งรหัสไป เซิร์ฟเวอร์จะใช้ชุดกฎของเทมเพลตเป็นฐาน
        expect(applied.matchingTemplate(custom), isNull);
        expect(applied.withMatchedTemplate(custom).toRequestJson().containsKey('template'), isFalse);
      }
    });

    test('คำขอแก้บอทไม่ส่ง rules และไม่ส่ง template — ช่องอื่นส่งครบ', () {
      final catalog = _liveCatalog();
      final custom = catalog.strategy('custom')!;
      final bot = _customBot(_rules());

      final sent = AiBotDraft.fromBot(bot)
          .copyWith(name: 'ชื่อใหม่', template: 'trend_follow')
          .forUpdateOf(bot, custom, catalog)
          .toRequestJson();
      final params = sent['params'] as Map;

      expect(sent['name'], 'ชื่อใหม่');
      expect(sent.containsKey('template'), isFalse);
      expect(params.containsKey('rules'), isFalse);
      expect(params['order_size_usd'], 40);
      expect(params['time_stop_mode'], 'no_profit');
      expect(params['news_mode'], 'off');
    });

    test('ชุดกฎที่เซิร์ฟเวอร์ติดธง invalid ไม่ถูกส่งกลับ — แก้ชื่อ/ความเสี่ยงของบอทตัวนั้นยังทำได้', () {
      final catalog = _liveCatalog();
      final custom = catalog.strategy('custom')!;
      final bot = _customBot(_rules(invalid: true));

      final sent = jsonEncode(AiBotDraft.fromBot(bot).copyWith(maxPositionUsd: 250).forUpdateOf(bot, custom, catalog).toRequestJson());

      expect(sent, isNot(contains('invalid')));
      expect(sent, isNot(contains('"rules"')));
      expect(sent, contains('"max_position_usd":250'));
    });

    test('เปลี่ยนกลยุทธ์ของบอท หรือกลยุทธ์ที่ไม่มีช่องแบบนี้ = ร่างไม่ถูกแตะ', () {
      final catalog = _liveCatalog();
      final grid = catalog.strategy('grid')!;
      final custom = catalog.strategy('custom')!;

      // บอท grid ธรรมดา: ไม่มีอะไรให้ตัด และรหัสเทมเพลตยังอยู่
      final gridBot = AiBot.fromJson(aiBotJson(strategy: 'grid'));
      final gridDraft = AiBotDraft.fromBot(gridBot).copyWith(template: 'balanced');
      expect(identical(gridDraft.forUpdateOf(gridBot, grid, catalog), gridDraft), isTrue);

      // ร่างเป็น custom แต่บอทเดิมเป็น grid (ย้ายกลยุทธ์): ไม่มีชุดเดิมให้เซิร์ฟเวอร์เก็บ — ไม่ตัดอะไรออก
      final moved = AiBotDraft.fromBot(_customBot(_rules()));
      expect(identical(moved.forUpdateOf(gridBot, custom, catalog), moved), isTrue);
    });
  });
}
