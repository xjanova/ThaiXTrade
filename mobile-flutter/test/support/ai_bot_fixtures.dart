/// TPIX TRADE — ของกลางสำหรับเทสต์ฟอร์มบอท AI (แคตตาล็อก · สถานะ · API ปลอม · กระเป๋าปลอม)
///
/// ใช้ร่วมกันระหว่างเทสต์ระดับโมเดล/ผู้ให้บริการ กับเทสต์ที่วาดชีตสร้างบอทจริง
/// รูปข้อมูลถอดจาก `/api/v1/ai-bot/catalog` และ `/api/v1/ai-bot/status` ตรงตัว
///
/// Developed by Xman Studio
library;

import 'package:tpix_trade/models/ai_bot_models.dart';
import 'package:tpix_trade/providers/wallet_provider.dart';
import 'package:tpix_trade/services/ai_bot_api.dart';
import 'package:tpix_trade/services/api_result.dart';

const kTestWallet = '0xabcdef0123456789abcdef0123456789abcdef01';

/// แคตตาล็อกรูปเดียวกับ GET /api/v1/ai-bot/catalog (ย่อเหลือส่วนที่ฟอร์มใช้)
/// มีครบทุกสถานะของกลยุทธ์: ใช้ได้ · ล็อกตามแพลน · เร็วๆ นี้ · ถอดออกจากการขาย ·
/// และ `custom` ที่มีช่องชนิด `rules` ซึ่งแอพรุ่นนี้ไม่รู้จัก
Map<String, dynamic> aiBotCatalogJson() => {
      'timeframes': ['1m', '5m', '15m', '1h', '4h', '1d'],
      'limits': {
        'max_name_length': 60,
        'stop_loss_pct': {'min': 0.5, 'max': 50, 'default': 5},
        'take_profit_pct': {'min': 0.5, 'max': 200, 'default': 10},
        'max_position_usd': {'min': 10, 'max': 1000000, 'default': 100},
        'max_daily_loss_usd': {'min': 5, 'max': 1000000, 'default': 50},
      },
      'features': {
        'live_trading': true,
        'live_execution': true,
        'credit_topup': false,
        'credit_topup_ready': true,
        'sales_open': false,
      },
      'common_params': <Map<String, dynamic>>[
        {
          'key': 'news_mode',
          'label': 'ข่าวมีผลต่อการซื้อขายแค่ไหน',
          'label_en': 'How news affects trading',
          'type': 'select',
          'default': 'confirm_exit',
          'options': ['off', 'block_entries', 'confirm_exit', 'immediate_exit'],
          'option_labels': {
            'off': {'th': 'ไม่ใช้ข่าว', 'en': 'Ignore news'},
          },
        },
        {'key': 'auto_pair', 'type': 'bool', 'default': false},
        {'key': 'ai_gate', 'type': 'bool', 'default': true},
        {'key': 'macro_filter', 'type': 'bool', 'default': true},
        {
          'key': 'macro_ema',
          'type': 'select',
          'default': '50',
          'options': ['50', '100', '200'],
          'group': 'advanced',
        },
      ],
      'strategies': [
        {
          'code': 'grid',
          'name': 'Grid Trading',
          'name_th': 'ตารางเทรด (Grid)',
          'tier': 'free',
          'timeframes': ['1h', '4h'],
          'default_timeframe': '4h',
          'available': true,
          'coming_soon': false,
          'params': [
            {'key': 'grid_levels', 'type': 'number', 'default': 8, 'min': 3, 'max': 60, 'step': 1},
            {'key': 'range_pct', 'type': 'number', 'default': 10, 'min': 0.5, 'max': 50, 'step': 0.5},
            {'key': 'regime_filter', 'type': 'bool', 'default': true, 'group': 'advanced'},
            {'key': 'er_max', 'type': 'number', 'default': 0.35, 'min': 0.1, 'max': 0.9, 'group': 'advanced'},
          ],
          'templates': [
            {
              'code': 'conservative',
              'name': 'Wide & slow',
              'name_th': 'กรอบกว้าง เก็บช้า',
              'tagline_th': '⚠️ ขาดทุน −3.8% — ไม่ชนะต้นทุนเข้า-ออก',
              'tagline_en': '⚠️ −3.8% on capital — does not beat trading costs',
              'timeframe': '4h',
              'params': {'grid_levels': 5, 'range_pct': 10, 'regime_filter': true, 'er_max': 0.3},
              'risk': {'max_position_usd': 100, 'stop_loss_pct': 8, 'take_profit_pct': 20, 'max_daily_loss_usd': 50},
            },
            {
              'code': 'balanced',
              'name': 'Balanced',
              'name_th': 'สมดุล',
              'timeframe': '4h',
              'params': {'grid_levels': 8, 'range_pct': 10, 'regime_filter': true, 'er_max': 0.35},
              'risk': {'max_position_usd': 100, 'stop_loss_pct': 6, 'take_profit_pct': 15, 'max_daily_loss_usd': 50},
            },
            {
              'code': 'aggressive',
              'name': 'Faster grid',
              'timeframe': '1h',
              // ชุดนี้ไม่ระบุ er_max — ช่องนั้นต้องคงค่าที่อยู่ในฟอร์ม (กติกาของเว็บ)
              'params': {'grid_levels': 8, 'range_pct': 10, 'regime_filter': true},
              'risk': {'max_position_usd': 100, 'stop_loss_pct': 5, 'take_profit_pct': 10, 'max_daily_loss_usd': 50},
            },
          ],
        },
        {
          'code': 'momentum',
          'name': 'Momentum Trend',
          'tier': 'basic',
          'timeframes': ['1h', '4h'],
          'available': true,
          'params': [
            {'key': 'fast_ema', 'type': 'number', 'default': 12, 'min': 2, 'max': 100},
          ],
          // ไม่มีชุด "balanced" — ชุดตั้งต้นต้องเป็นใบแรก เหมือนหน้าเว็บ
          'templates': [
            {'code': 'slow', 'timeframe': '4h', 'params': {'fast_ema': 20}, 'risk': {'stop_loss_pct': 4}},
            {'code': 'fast', 'timeframe': '1h', 'params': {'fast_ema': 8}, 'risk': {'stop_loss_pct': 6}},
          ],
        },
        {
          'code': 'scalping',
          'name': 'Micro Scalper',
          'tier': 'pro',
          'timeframes': ['1m', '5m'],
          'retired': true,
          'available': false,
          'coming_soon': false,
          'unavailable_reason': 'ถอดออกจากการขายแล้ว — ขาดทุนโดยโครงสร้าง',
          'unavailable_reason_en': 'Retired — it loses by construction.',
          'params': [
            {'key': 'target_bps', 'type': 'number', 'default': 45, 'min': 40, 'max': 300},
          ],
        },
        {
          'code': 'arbitrage',
          'name': 'Spread Arbitrage',
          'tier': 'vip',
          'timeframes': ['1m', '5m'],
          'available': false,
          'coming_soon': true,
          'unavailable_reason': 'เร็วๆ นี้ — กลยุทธ์นี้อยู่ระหว่างพัฒนา ยังไม่เปิดให้สร้างบอท',
          'unavailable_reason_en': 'Coming soon — this strategy is still in development.',
          'params': [
            {'key': 'min_edge_bps', 'type': 'number', 'default': 25, 'min': 5, 'max': 500},
          ],
        },
        {
          // กลยุทธ์ที่กำลังจะเข้าแคตตาล็อก: ชุดกฎแบบมีโครงสร้าง แอพรุ่นนี้ยังแก้ไม่ได้
          'code': 'custom',
          'name': 'Custom rules',
          'name_th': 'กฎกำหนดเอง',
          'tier': 'free',
          'timeframes': ['1h', '4h'],
          'available': true,
          'params': [
            {
              'key': 'rules',
              'label': 'ชุดกฎ',
              'type': 'rules',
              'default': {'entry': [], 'exit': []},
            },
            {'key': 'cooldown_bars', 'type': 'number', 'default': 3, 'min': 0, 'max': 50},
          ],
        },
      ],
    };

Map<String, dynamic> aiBotJson({
  int id = 7,
  String strategy = 'grid',
  String status = 'paused',
  Map<String, dynamic>? params,
  Map<String, dynamic> extra = const {},
}) =>
    {
      'id': id,
      'name': 'บอท $id',
      'pair': 'BTC/USDT',
      'strategy': strategy,
      'timeframe': '4h',
      'params': params ?? {'grid_levels': 8, 'range_pct': 10},
      'risk': {
        'max_position_usd': 100,
        'max_position_usd_requested': 100,
        'stop_loss_pct': 6,
        'take_profit_pct': 15,
        'max_daily_loss_usd': 50,
      },
      'status': status,
      'mode': 'demo',
      ...extra,
    };

Map<String, dynamic> aiBotStatusJson({
  bool withPlan = true,
  int maxBots = 3,
  List<Map<String, dynamic>> bots = const [],
}) =>
    {
      'wallet_address': kTestWallet,
      'credits': 100,
      'is_active': withPlan,
      'subscription': withPlan
          ? {'id': 1, 'plan_code': 'free', 'tier': 'free', 'execution': 'browser', 'is_free': true}
          : null,
      'quota': {'max_bots': withPlan ? maxBots : 0, 'used_bots': bots.length},
      'unlocked_strategies': withPlan ? ['grid', 'custom'] : <String>[],
      'is_admin': false,
      'bots': bots,
    };

// ── ตัวปลอมของชั้นเครือข่าย ────────────────────────────────────────────────

/// API ปลอม — ตอบตามที่เทสต์ตั้งไว้ และจำคำขอสร้างบอทที่ถูกส่งมา
class FakeBotApi implements AiBotApi {
  ApiResult<AiBotStatus> status = ApiOk(AiBotStatus.fromJson(aiBotStatusJson()));
  ApiResult<AiBot> created = ApiOk(AiBot.fromJson(aiBotJson(id: 42)));

  /// ตั้งไว้ = การดึงสถานะ "หลังสร้างบอท" ตอบค่านี้แทน (จำลองเน็ตหลุด/โดนจำกัดอัตรา)
  ApiResult<AiBotStatus>? statusAfterCreate;

  /// แคตตาล็อกที่จะตอบ (null = ชุดมาตรฐานของ [aiBotCatalogJson])
  Map<String, dynamic>? catalogJson;

  final List<AiBotDraft> drafts = [];

  @override
  Duration get cooldownRemaining => Duration.zero;

  @override
  Future<ApiResult<AiBotCatalog>> fetchCatalog() async => ApiOk(AiBotCatalog.fromJson(catalogJson ?? aiBotCatalogJson()));

  @override
  Future<ApiResult<AiMarketView>> fetchMarketView() async =>
      ApiOk(AiMarketView.fromJson(const {}));

  @override
  Future<ApiResult<AiBotStatus>> fetchStatus(String wallet) async =>
      drafts.isEmpty ? status : (statusAfterCreate ?? status);

  @override
  Future<ApiResult<AiBotDemo>> fetchDemo(String wallet) async =>
      ApiOk(AiBotDemo.fromJson(const {}));

  @override
  Future<ApiResult<AiBot>> createBot({
    required String wallet,
    required AiBotDraft draft,
  }) async {
    drafts.add(draft);
    return created;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

/// กระเป๋าปลอม — แค่ที่อยู่ (ไม่แตะ secure storage / เน็ต)
class FakeBotWallet extends WalletProvider {
  @override
  String? get address => kTestWallet;

  @override
  bool get isConnected => true;
}
