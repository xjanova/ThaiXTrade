/// TPIX TRADE — ฟอร์มบอท AI บนแอป: สลับกลยุทธ์แล้วค่าร่วมที่ผู้ใช้ตั้งไว้ต้องไม่หาย
///
/// เดิม [AiBotDraft.withStrategy] รีเซ็ตทุกช่องเป็นค่าปริยายก่อนใช้เทมเพลต ผู้ใช้ที่ปิดข่าวไว้
/// (news_mode = off) สลับกลยุทธ์แล้วได้ "ขายเมื่อราคายืนยัน" คืนมาเงียบๆ ขณะที่หน้าเว็บ
/// (`syncParamDefaults()` ใน AiTrade.vue) เก็บค่าไว้ — สองแอปทำงานไม่เหมือนกัน
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/ai_bot_models.dart';

/// แคตตาล็อกย่อรูปเดียวกับที่ /api/v1/ai-bot/catalog ส่งมา — เฉพาะส่วนที่ฟอร์มใช้
AiBotCatalog _catalog() => AiBotCatalog.fromJson({
  'timeframes': ['1h', '4h', '1d'],
  'common_params': [
    {
      'key': 'news_mode',
      'type': 'select',
      'default': 'confirm_exit',
      'options': ['off', 'block_entries', 'confirm_exit', 'immediate_exit'],
    },
    {'key': 'auto_pair', 'type': 'bool', 'default': false},
    {'key': 'macro_filter', 'type': 'bool', 'default': true},
    {
      'key': 'macro_ema',
      'type': 'select',
      'default': '50',
      'options': ['50', '100', '200'],
    },
  ],
  'strategies': [
    {
      'code': 'momentum',
      'timeframes': ['1h', '4h'],
      'default_timeframe': '4h',
      'params': [
        {
          'key': 'fast_ema',
          'type': 'number',
          'default': 12,
          'min': 2,
          'max': 100,
        },
        {
          'key': 'max_hold_bars',
          'type': 'number',
          'default': 48,
          'min': 0,
          'max': 500,
        },
      ],
      'templates': [
        {
          'code': 'balanced',
          'timeframe': '4h',
          'params': {'fast_ema': 12, 'max_hold_bars': 48},
          'risk': {'stop_loss_pct': 5, 'take_profit_pct': 10},
        },
      ],
    },
    {
      'code': 'breakout',
      'timeframes': ['4h', '1d'],
      'default_timeframe': '4h',
      'params': [
        {
          'key': 'channel_period',
          'type': 'number',
          'default': 20,
          'min': 5,
          'max': 200,
        },
        {
          'key': 'max_hold_bars',
          'type': 'number',
          'default': 120,
          'min': 0,
          'max': 500,
        },
      ],
      'templates': [
        {
          'code': 'balanced',
          'timeframe': '4h',
          'params': {'channel_period': 20, 'max_hold_bars': 120},
          'risk': {'stop_loss_pct': 6, 'take_profit_pct': 20},
        },
      ],
    },
    // สองตัวนี้ไม่มีเทมเพลต — ดูการยกค่าข้ามกลยุทธ์ได้ตรงๆ โดยไม่มีเทมเพลตทับ
    {
      'code': 'trend_plain',
      'timeframes': ['1h', '4h'],
      'params': [
        {
          'key': 'max_hold_bars',
          'type': 'number',
          'default': 48,
          'min': 0,
          'max': 500,
        },
        {'key': 'htf_confirm', 'type': 'bool', 'default': true},
      ],
    },
    {
      'code': 'swing_plain',
      'timeframes': ['1h', '4h'],
      'params': [
        // ช่องชื่อเดียวกันแต่กรอบแคบกว่า — ค่าที่หลุดกรอบต้องกลับเป็นค่าปริยาย
        {
          'key': 'max_hold_bars',
          'type': 'number',
          'default': 60,
          'min': 0,
          'max': 100,
        },
        {'key': 'htf_confirm', 'type': 'bool', 'default': false},
      ],
    },
  ],
});

AiBotDraft _draftFor(AiBotCatalog catalog, String code) => AiBotDraft.blank(
  catalog: catalog,
  strategy: catalog.strategy(code)!,
  name: 'ทดสอบ',
  pair: 'BTC/USDT',
);

AiBotDraft _set(AiBotDraft d, Map<String, dynamic> values) =>
    d.copyWith(params: {...d.params, ...values}, clearTemplate: true);

void main() {
  group('สลับกลยุทธ์', () {
    test('ค่าร่วมที่ผู้ใช้ตั้งไว้ติดไปด้วย — ปิดข่าวไว้ก็ยังปิด', () {
      final catalog = _catalog();
      final draft = _set(_draftFor(catalog, 'momentum'), {
        'news_mode': 'off',
        'auto_pair': true,
        'macro_filter': false,
        'macro_ema': '200',
      });

      final switched = draft.withStrategy(
        catalog.strategy('breakout')!,
        catalog,
      );

      expect(switched.strategy, 'breakout');
      expect(switched.params['news_mode'], 'off');
      expect(switched.params['auto_pair'], true);
      expect(switched.params['macro_filter'], false);
      expect(switched.params['macro_ema'], '200');
    });

    test(
      'ช่องที่กลยุทธ์ใหม่ไม่มีถูกทิ้ง และช่องของกลยุทธ์ใหม่ได้ค่าตั้งต้นของมัน',
      () {
        final catalog = _catalog();
        final draft = _set(_draftFor(catalog, 'momentum'), {'fast_ema': 30});

        final switched = draft.withStrategy(
          catalog.strategy('breakout')!,
          catalog,
        );

        expect(
          switched.params.containsKey('fast_ema'),
          isFalse,
          reason: 'ช่องของ momentum ต้องไม่ติดไป',
        );
        expect(switched.params['channel_period'], 20);
        // มีเทมเพลต balanced = เริ่มจากชุดแนะนำของกลยุทธ์ใหม่ (พฤติกรรมเดิม)
        expect(switched.params['max_hold_bars'], 120);
        expect(switched.template, 'balanced');
        expect(switched.timeframe, '4h');
        expect(switched.stopLossPct, 6);
      },
    );

    test(
      'ไม่มีเทมเพลต: ช่องที่ขาดได้ค่าปริยายของกลยุทธ์ใหม่ ค่าร่วมยังติดไป',
      () {
        final catalog = _catalog();
        final draft = _set(_draftFor(catalog, 'momentum'), {
          'news_mode': 'block_entries',
        });

        final switched = draft.withStrategy(
          catalog.strategy('trend_plain')!,
          catalog,
        );

        expect(switched.params['news_mode'], 'block_entries');
        expect(
          switched.params['htf_confirm'],
          true,
          reason: 'ช่องใหม่ = ค่าปริยายของกลยุทธ์ใหม่',
        );
        expect(switched.params['max_hold_bars'], 48);
        expect(switched.params.keys.toSet(), {
          'news_mode',
          'auto_pair',
          'macro_filter',
          'macro_ema',
          'max_hold_bars',
          'htf_confirm',
        });
        expect(switched.template, isNull);
      },
    );

    test(
      'ช่องของกลยุทธ์ที่ชื่อซ้ำติดไปเฉพาะเมื่อค่ายังอยู่ในกรอบของช่องใหม่',
      () {
        final catalog = _catalog();
        final plain = _draftFor(catalog, 'trend_plain');
        final swing = catalog.strategy('swing_plain')!;

        final inRange = _set(plain, {
          'max_hold_bars': 80,
          'htf_confirm': true,
        }).withStrategy(swing, catalog);
        expect(inRange.params['max_hold_bars'], 80);
        expect(
          inRange.params['htf_confirm'],
          true,
          reason: 'ค่าที่ผู้ใช้ตั้งชนะค่าปริยายของกลยุทธ์ใหม่',
        );

        final outOfRange = _set(plain, {
          'max_hold_bars': 300,
        }).withStrategy(swing, catalog);
        expect(
          outOfRange.params['max_hold_bars'],
          60,
          reason: 'เกินเพดาน 100 ของช่องใหม่ = ค่าปริยาย',
        );
      },
    );

    test(
      'ค่าร่วมที่ไม่อยู่ในตัวเลือกแล้วกลับเป็นค่าปริยาย ไม่ติดไปทั้งที่ใช้ไม่ได้',
      () {
        final catalog = _catalog();
        final draft = _set(_draftFor(catalog, 'momentum'), {
          'news_mode': 'mystery',
          'macro_ema': 75,
        });

        final switched = draft.withStrategy(
          catalog.strategy('breakout')!,
          catalog,
        );

        expect(switched.params['news_mode'], 'confirm_exit');
        expect(switched.params['macro_ema'], '50');
      },
    );
  });

  group('กดเทมเพลต (แยกจากการสลับกลยุทธ์ — ต้องทำเหมือนเดิม)', () {
    test(
      'ค่าของเทมเพลตทับช่องของกลยุทธ์ ค่าร่วมที่เทมเพลตไม่ระบุยังเป็นของผู้ใช้',
      () {
        final catalog = _catalog();
        final momentum = catalog.strategy('momentum')!;
        final draft = _set(_draftFor(catalog, 'momentum'), {
          'fast_ema': 30,
          'news_mode': 'off',
        });

        final applied = draft.applyTemplate(
          momentum.template('balanced')!,
          momentum,
          catalog,
        );

        expect(
          applied.params['fast_ema'],
          12,
          reason: 'เทมเพลตทับค่าที่ผู้ใช้แก้',
        );
        expect(applied.params['news_mode'], 'off');
        expect(applied.template, 'balanced');
      },
    );

    test('เทมเพลตที่ระบุค่าร่วมเอง ทับค่าของผู้ใช้', () {
      final catalog = _catalog();
      final momentum = catalog.strategy('momentum')!;
      final custom = StrategyTemplate.fromJson({
        'code': 'safe',
        'timeframe': '4h',
        'params': {'news_mode': 'immediate_exit'},
      });

      final applied = _set(_draftFor(catalog, 'momentum'), {
        'news_mode': 'off',
      }).applyTemplate(custom, momentum, catalog);

      expect(applied.params['news_mode'], 'immediate_exit');
    });
  });
}
