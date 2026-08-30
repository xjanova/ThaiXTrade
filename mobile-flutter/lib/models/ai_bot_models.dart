/// TPIX TRADE — โครงข้อมูลของ AI TRADE (บอทคลาวด์)
///
/// ถอดจากสัญญา `/api/v1/ai-bot/*` ตรงตัว ทุกกับดักชนิดข้อมูลถูกกันไว้ในนี้แล้ว
/// เพื่อให้หน้าจอไม่ต้องรู้เรื่อง JSON:
///   • ตัวเลขจาก JSON มาเป็น int บ้าง double บ้าง → แปลงผ่าน `_d` เสมอ
///   • ค่าที่ "ไม่มีข้อมูล" ต้องเป็น null ไม่ใช่ 0 (0 กับ ไม่รู้ ต่างกันมากเรื่องเงิน)
///   • คีย์บางตัวหายไปทั้งคีย์ ไม่ใช่เป็น null (เช่น change_1h ตอนประเมินไม่ได้)
///
/// Developed by Xman Studio

library;

// ══════════════════════════════════════════════════════════════════
// แคตตาล็อก (public — ดูได้ก่อนเชื่อมกระเป๋า)
// ══════════════════════════════════════════════════════════════════

/// ธงเปิด/ปิดฟีเจอร์จากเซิร์ฟเวอร์ — ห้ามฮาร์ดโค้ดค่าพวกนี้ในหน้าจอ
class AiBotFeatures {
  final bool liveTrading;
  final bool creditTopup;
  final bool salesOpen;

  const AiBotFeatures({
    required this.liveTrading,
    required this.creditTopup,
    required this.salesOpen,
  });

  factory AiBotFeatures.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return AiBotFeatures(
      liveTrading: m['live_trading'] == true,
      creditTopup: m['credit_topup'] == true,
      salesOpen: m['sales_open'] == true,
    );
  }

  /// ค่าปลอดภัยตอนยังโหลดแคตตาล็อกไม่สำเร็จ — ปิดทุกอย่างไว้ก่อน
  /// (เดาว่าเปิดแล้วปล่อยผู้ใช้กดจนเจอ error คือสิ่งที่ห้ามทำ)
  static const AiBotFeatures closed = AiBotFeatures(
    liveTrading: false,
    creditTopup: false,
    salesOpen: false,
  );
}

/// ช่วงค่าที่ยอมรับของกรอบความเสี่ยงหนึ่งช่อง
class LimitRange {
  final double min;
  final double max;
  final double defaultValue;

  const LimitRange({
    required this.min,
    required this.max,
    required this.defaultValue,
  });

  factory LimitRange.fromJson(Map<String, dynamic>? json, LimitRange fallback) {
    if (json == null) return fallback;
    return LimitRange(
      min: _d(json['min'], fallback.min),
      max: _d(json['max'], fallback.max),
      defaultValue: _d(json['default'], fallback.defaultValue),
    );
  }

  double clampValue(double v) => v.clamp(min, max);
}

/// เพดานต่างๆ ที่เซิร์ฟเวอร์ประกาศ — ฟอร์มต้องอ่านจากตรงนี้ ไม่ใช่ฝังเลขเอง
class AiBotLimits {
  final int maxBotsHardCap;
  final int maxNameLength;
  final LimitRange stopLossPct;
  final LimitRange takeProfitPct;
  final LimitRange maxPositionUsd;
  final LimitRange maxDailyLossUsd;

  const AiBotLimits({
    required this.maxBotsHardCap,
    required this.maxNameLength,
    required this.stopLossPct,
    required this.takeProfitPct,
    required this.maxPositionUsd,
    required this.maxDailyLossUsd,
  });

  static const AiBotLimits fallback = AiBotLimits(
    maxBotsHardCap: 25,
    maxNameLength: 60,
    stopLossPct: LimitRange(min: 0.5, max: 50, defaultValue: 5),
    takeProfitPct: LimitRange(min: 0.5, max: 200, defaultValue: 10),
    maxPositionUsd: LimitRange(min: 10, max: 1000000, defaultValue: 100),
    maxDailyLossUsd: LimitRange(min: 5, max: 1000000, defaultValue: 50),
  );

  factory AiBotLimits.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return AiBotLimits(
      maxBotsHardCap: _i(m['max_bots_hard_cap'], fallback.maxBotsHardCap),
      maxNameLength: _i(m['max_name_length'], fallback.maxNameLength),
      stopLossPct: LimitRange.fromJson(
        _map(m['stop_loss_pct']),
        fallback.stopLossPct,
      ),
      takeProfitPct: LimitRange.fromJson(
        _map(m['take_profit_pct']),
        fallback.takeProfitPct,
      ),
      maxPositionUsd: LimitRange.fromJson(
        _map(m['max_position_usd']),
        fallback.maxPositionUsd,
      ),
      maxDailyLossUsd: LimitRange.fromJson(
        _map(m['max_daily_loss_usd']),
        fallback.maxDailyLossUsd,
      ),
    );
  }
}

/// หนึ่งช่องพารามิเตอร์ของกลยุทธ์ (หรือสวิตช์ร่วมของทุกกลยุทธ์)
class StrategyParam {
  final String key;
  final String label;
  final String labelEn;
  final String type; // number | bool | select
  final dynamic defaultValue;
  final double? min;
  final double? max;
  final double? step;
  final List<String> options;

  const StrategyParam({
    required this.key,
    required this.label,
    required this.labelEn,
    required this.type,
    this.defaultValue,
    this.min,
    this.max,
    this.step,
    this.options = const [],
  });

  factory StrategyParam.fromJson(Map<String, dynamic> json) {
    final key = _s(json['key']);
    final label = _s(json['label'], key);
    return StrategyParam(
      key: key,
      label: label,
      labelEn: _s(json['label_en'], label),
      type: _s(json['type'], 'number'),
      defaultValue: json['default'],
      min: _dn(json['min']),
      max: _dn(json['max']),
      step: _dn(json['step']),
      options: _strList(json['options']),
    );
  }

  bool get isNumber => type == 'number';
  bool get isBool => type == 'bool';
  bool get isSelect => type == 'select';

  String labelFor(bool isThai) => isThai ? label : labelEn;
}

/// หนึ่งกลยุทธ์
class AiBotStrategy {
  final String code;
  final String name;
  final String nameTh;
  final String description;
  final String descriptionTh;
  final String risk; // low | medium | high
  final String tier; // free | basic | pro | vip
  final String icon;
  final List<String> timeframes;
  final List<StrategyParam> params;

  /// ⚠️ ต่างจาก [tier] — tier บอกว่า "แพลนไหนถึงปลดล็อก"
  /// ส่วนนี่บอกว่า "ลงมือได้จริงไหม" (เช่น arbitrage รอพูล DEX อยู่)
  /// อัปเกรดแพลนก็ยังใช้ไม่ได้ถ้าอันนี้เป็น false
  final bool available;
  final String? unavailableReason;
  final String? unavailableReasonEn;

  const AiBotStrategy({
    required this.code,
    required this.name,
    required this.nameTh,
    required this.description,
    required this.descriptionTh,
    required this.risk,
    required this.tier,
    required this.icon,
    required this.timeframes,
    required this.params,
    required this.available,
    this.unavailableReason,
    this.unavailableReasonEn,
  });

  factory AiBotStrategy.fromJson(Map<String, dynamic> json) {
    final code = _s(json['code']);
    final name = _s(json['name'], code);
    return AiBotStrategy(
      code: code,
      name: name,
      nameTh: _s(json['name_th'], name),
      description: _s(json['description']),
      descriptionTh: _s(json['description_th'], _s(json['description'])),
      risk: _s(json['risk'], 'medium'),
      tier: _s(json['tier'], 'free'),
      icon: _s(json['icon'], 'spark'),
      timeframes: _strList(json['timeframes']),
      params: _mapList(
        json['params'],
      ).map(StrategyParam.fromJson).toList(growable: false),
      // ไม่ส่งมา = ใช้ได้ (เซิร์ฟเวอร์รุ่นเก่าไม่มีคีย์นี้)
      available: json['available'] != false,
      unavailableReason: _sn(json['unavailable_reason']),
      unavailableReasonEn: _sn(json['unavailable_reason_en']),
    );
  }

  String label(bool isThai) => isThai ? nameTh : name;

  String describe(bool isThai) => isThai ? descriptionTh : description;

  /// เหตุผลที่ยังใช้ไม่ได้ — null ถ้าใช้ได้ปกติ
  String? blockedReason(bool isThai) {
    if (available) return null;
    final th = unavailableReason;
    final en = unavailableReasonEn;
    if (isThai) return th ?? en;
    return en ?? th;
  }

  /// ค่าเริ่มต้นของพารามิเตอร์ทั้งชุด
  Map<String, dynamic> defaultParams(List<StrategyParam> commonParams) {
    final out = <String, dynamic>{};
    // สวิตช์ร่วมมาก่อน แล้วให้ของกลยุทธ์เขียนทับคีย์ที่ชื่อซ้ำ
    for (final p in commonParams) {
      out[p.key] = p.defaultValue;
    }
    for (final p in params) {
      out[p.key] = p.defaultValue;
    }
    return out;
  }

  /// ชุดช่องที่ต้องวาดในฟอร์ม — สวิตช์ร่วมก่อน แล้วของกลยุทธ์เขียนทับ
  ///
  /// ถ้าวาดจาก [params] อย่างเดียว สวิตช์ "หยุดเทรดช่วงข่าวแรง" กับ
  /// "ให้ AI เลือกเหรียญให้" จะไม่มีวันโผล่บนจอ
  List<StrategyParam> formSpecs(List<StrategyParam> commonParams) {
    final merged = <String, StrategyParam>{};
    for (final p in commonParams) {
      merged[p.key] = p;
    }
    for (final p in params) {
      merged[p.key] = p;
    }
    return merged.values.toList(growable: false);
  }
}

/// หนึ่งแพลนเช่า
class AiBotPlan {
  final String code;
  final String name;
  final String? nameTh;
  final String? description;
  final String? descriptionTh;
  final String tier;
  final String execution; // browser | cloud
  final int creditsPerDay;
  final double priceTpixPerDay;
  final int maxBots;

  /// null = ไม่จำกัดทุนต่อไม้
  ///
  /// ⚠️ `0.0` **ไม่ใช่** เพดาน — เซิร์ฟเวอร์ถือว่าต้อง `> 0` ถึงจะบีบ
  /// เช็ค truthiness ตรงๆ จะทำให้จอโฆษณาว่า "ไม่จำกัด" ทั้งที่เปิดไม้ไม่ได้เลย
  final double? maxCapitalUsd;

  final List<String> features;
  final List<String> featuresTh;
  final String? badge;
  final List<String> strategies;

  const AiBotPlan({
    required this.code,
    required this.name,
    this.nameTh,
    this.description,
    this.descriptionTh,
    required this.tier,
    required this.execution,
    required this.creditsPerDay,
    required this.priceTpixPerDay,
    required this.maxBots,
    this.maxCapitalUsd,
    this.features = const [],
    this.featuresTh = const [],
    this.badge,
    this.strategies = const [],
  });

  factory AiBotPlan.fromJson(Map<String, dynamic> json) {
    return AiBotPlan(
      code: _s(json['code']),
      name: _s(json['name'], _s(json['code'])),
      nameTh: _sn(json['name_th']),
      description: _sn(json['description']),
      descriptionTh: _sn(json['description_th']),
      tier: _s(json['tier'], 'free'),
      execution: _s(json['execution'], 'browser'),
      creditsPerDay: _i(json['credits_per_day'], 0),
      priceTpixPerDay: _d(json['price_tpix_per_day'], 0),
      maxBots: _i(json['max_bots'], 0),
      maxCapitalUsd: _dn(json['max_capital_usd']),
      features: _strList(json['features']),
      featuresTh: _strList(json['features_th']),
      badge: _sn(json['badge']),
      strategies: _strList(json['strategies']),
    );
  }

  bool get isFree => priceTpixPerDay <= 0;
  bool get runsInCloud => execution == 'cloud';

  /// มีเพดานทุนต่อไม้จริงไหม (กันกับดัก 0.0)
  bool get hasCapitalCap => maxCapitalUsd != null && maxCapitalUsd! > 0;

  String label(bool isThai) {
    if (isThai) {
      final th = nameTh;
      if (th != null && th.isNotEmpty) return th;
    }
    return name;
  }

  String? describe(bool isThai) {
    if (isThai) {
      final th = descriptionTh;
      if (th != null && th.isNotEmpty) return th;
    }
    return description;
  }

  List<String> featureList(bool isThai) {
    if (isThai && featuresTh.isNotEmpty) return featuresTh;
    if (!isThai && features.isNotEmpty) return features;
    return features.isNotEmpty ? features : featuresTh;
  }

  /// ราคารวมของจำนวนวันที่เลือก (หน่วย TPIX)
  double totalPrice(int days) => priceTpixPerDay * days;

  /// เครดิตที่จะถูกตัดสำหรับจำนวนวันที่เลือก
  int totalCredits(int days) => creditsPerDay * days;
}

/// แพ็กเติมเครดิต
class CreditPack {
  final String code;
  final int credits;
  final int priceTpix;
  final int bonus;

  const CreditPack({
    required this.code,
    required this.credits,
    required this.priceTpix,
    required this.bonus,
  });

  factory CreditPack.fromJson(Map<String, dynamic> json) => CreditPack(
    code: _s(json['code']),
    credits: _i(json['credits'], 0),
    priceTpix: _i(json['price_tpix'], 0),
    bonus: _i(json['bonus'], 0),
  );

  /// เครดิตที่ได้จริงรวมโบนัส
  int get totalCredits => credits + bonus;
}

/// แคตตาล็อกทั้งชุด
class AiBotCatalog {
  final List<AiBotPlan> plans;
  final List<AiBotStrategy> strategies;
  final List<CreditPack> packs;
  final List<int> rentalDays;
  final List<String> timeframes;
  final AiBotLimits limits;
  final List<StrategyParam> commonParams;
  final bool analystEnabled;
  final AiBotFeatures features;

  const AiBotCatalog({
    required this.plans,
    required this.strategies,
    required this.packs,
    required this.rentalDays,
    required this.timeframes,
    required this.limits,
    required this.commonParams,
    required this.analystEnabled,
    required this.features,
  });

  factory AiBotCatalog.fromJson(Map<String, dynamic> json) {
    final days = _numList(
      json['rental_days'],
    ).map((e) => e.toInt()).where((e) => e > 0).toList(growable: false);

    return AiBotCatalog(
      plans: _mapList(
        json['plans'],
      ).map(AiBotPlan.fromJson).toList(growable: false),
      strategies: _mapList(
        json['strategies'],
      ).map(AiBotStrategy.fromJson).toList(growable: false),
      packs: _mapList(
        json['packs'],
      ).map(CreditPack.fromJson).toList(growable: false),
      rentalDays: days.isEmpty ? const [1, 7, 30] : days,
      timeframes: _strList(json['timeframes']),
      limits: AiBotLimits.fromJson(_map(json['limits'])),
      commonParams: _mapList(
        json['common_params'],
      ).map(StrategyParam.fromJson).toList(growable: false),
      analystEnabled: json['analyst_enabled'] == true,
      features: AiBotFeatures.fromJson(_map(json['features'])),
    );
  }

  AiBotStrategy? strategy(String code) {
    for (final s in strategies) {
      if (s.code == code) return s;
    }
    return null;
  }

  AiBotPlan? plan(String? code) {
    if (code == null) return null;
    for (final p in plans) {
      if (p.code == code) return p;
    }
    return null;
  }

  CreditPack? pack(String code) {
    for (final p in packs) {
      if (p.code == code) return p;
    }
    return null;
  }

  /// กรอบความเสี่ยงเริ่มต้นตามที่เซิร์ฟเวอร์ประกาศ
  Map<String, dynamic> defaultRisk() => {
    'max_position_usd': limits.maxPositionUsd.defaultValue,
    'stop_loss_pct': limits.stopLossPct.defaultValue,
    'take_profit_pct': limits.takeProfitPct.defaultValue,
    'max_daily_loss_usd': limits.maxDailyLossUsd.defaultValue,
  };
}

// ══════════════════════════════════════════════════════════════════
// สถานะกระเป๋า + บอท
// ══════════════════════════════════════════════════════════════════

/// การเช่าที่ใช้อยู่
class AiBotSubscription {
  final int id;
  final String? planCode;
  final String? planName;
  final String? planNameTh;
  final String? tier;
  final String? execution;

  /// ตัวตัดสิน "เช่าจริงหรือยัง" — ไม่ใช่ `AiBotStatus.isActive`
  final bool isFree;

  final int? creditsPerDay;
  final int daysRemaining;
  final DateTime? expiresAt;
  final DateTime? startedAt;

  const AiBotSubscription({
    required this.id,
    this.planCode,
    this.planName,
    this.planNameTh,
    this.tier,
    this.execution,
    required this.isFree,
    this.creditsPerDay,
    required this.daysRemaining,
    this.expiresAt,
    this.startedAt,
  });

  factory AiBotSubscription.fromJson(Map<String, dynamic> json) =>
      AiBotSubscription(
        id: _i(json['id'], 0),
        planCode: _sn(json['plan_code']),
        planName: _sn(json['plan_name']),
        planNameTh: _sn(json['plan_name_th']),
        tier: _sn(json['tier']),
        execution: _sn(json['execution']),
        isFree: json['is_free'] != false,
        creditsPerDay: _in(json['credits_per_day']),
        daysRemaining: _i(json['days_remaining'], 0),
        expiresAt: _dt(json['expires_at']),
        startedAt: _dt(json['started_at']),
      );

  /// เซิร์ฟเวอร์เดินบอทให้ไหม — false แปลว่าแอพต้องสั่งเดินเอง (tick)
  bool get runsInCloud => execution == 'cloud';

  /// กระเป๋าทีมงานได้แพลน `admin` ซึ่ง **ไม่อยู่ในแคตตาล็อก**
  /// อย่าไปหา plan_code ในรายการ catalog แล้วสมมติว่าต้องเจอ
  bool get isTeamPlan => planCode == 'admin';

  String label(bool isThai) {
    if (isThai) {
      final th = planNameTh;
      if (th != null && th.isNotEmpty) return th;
    }
    return planName ?? planCode ?? '—';
  }
}

/// โควตาบอท
class AiBotQuota {
  final int maxBots;
  final int usedBots;

  const AiBotQuota({required this.maxBots, required this.usedBots});

  factory AiBotQuota.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return AiBotQuota(
      maxBots: _i(m['max_bots'], 0),
      usedBots: _i(m['used_bots'], 0),
    );
  }

  static const AiBotQuota empty = AiBotQuota(maxBots: 0, usedBots: 0);

  bool get isFull => usedBots >= maxBots;
  int get remaining => (maxBots - usedBots).clamp(0, 9999);
}

/// กรอบความเสี่ยงของบอทหนึ่งตัว
class BotRisk {
  final double maxPositionUsd;

  /// ค่าที่ผู้ใช้ "ตั้งใจ" ไว้ ก่อนโดนเพดานแพลนบีบ
  final double maxPositionUsdRequested;

  final double stopLossPct;
  final double takeProfitPct;
  final double maxDailyLossUsd;

  const BotRisk({
    required this.maxPositionUsd,
    required this.maxPositionUsdRequested,
    required this.stopLossPct,
    required this.takeProfitPct,
    required this.maxDailyLossUsd,
  });

  factory BotRisk.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    final capped = _d(m['max_position_usd'], 0);
    return BotRisk(
      maxPositionUsd: capped,
      // ไม่มีคีย์ requested (บอทเก่า) = ผู้ใช้ตั้งใจเท่ากับที่ใช้จริง
      maxPositionUsdRequested: _d(m['max_position_usd_requested'], capped),
      stopLossPct: _d(m['stop_loss_pct'], 0),
      takeProfitPct: _d(m['take_profit_pct'], 0),
      maxDailyLossUsd: _d(m['max_daily_loss_usd'], 0),
    );
  }

  /// แพลนบีบทุนต่อไม้ลงจากที่ผู้ใช้ขอไหม
  bool get isCapped => maxPositionUsdRequested > maxPositionUsd + 0.000001;

  /// 🔴 สร้าง payload ส่งกลับเซิร์ฟเวอร์
  ///
  /// `sanitizeRisk()` ฝั่งเซิร์ฟเวอร์ให้ `max_position_usd_requested`
  /// **มีลำดับสูงกว่า** `max_position_usd` เสมอ — ถ้าส่งของเก่าติดไปด้วย
  /// ค่าที่ผู้ใช้เพิ่งพิมพ์จะถูกทิ้งเงียบๆ แล้วเซิร์ฟเวอร์ตอบ 200 เหมือนสำเร็จ
  /// (บั๊กที่ยังมีอยู่จริงบนเว็บ — ห้ามลอกมา)
  ///
  /// ทางแก้: ยัดค่าที่ผู้ใช้พิมพ์ลงทั้งสองคีย์ให้เท่ากัน
  static Map<String, dynamic> payload({
    required double maxPositionUsd,
    required double stopLossPct,
    required double takeProfitPct,
    required double maxDailyLossUsd,
  }) => {
    'max_position_usd': maxPositionUsd,
    'max_position_usd_requested': maxPositionUsd,
    'stop_loss_pct': stopLossPct,
    'take_profit_pct': takeProfitPct,
    'max_daily_loss_usd': maxDailyLossUsd,
  };

  Map<String, dynamic> toPayload() => payload(
    // เปิดฟอร์มแก้ไขต้องตั้งต้นจากค่าที่ผู้ใช้ตั้งใจ ไม่ใช่ค่าที่ถูกบีบ
    maxPositionUsd: maxPositionUsdRequested,
    stopLossPct: stopLossPct,
    takeProfitPct: takeProfitPct,
    maxDailyLossUsd: maxDailyLossUsd,
  );
}

/// ของที่บอทถืออยู่ (เฉพาะโหมดปัจจุบันของบอทตัวนั้น)
class BotPosition {
  final double quantity;
  final double entryPrice;
  final double costBasis;
  final int entryCount;
  final DateTime? openedAt;

  const BotPosition({
    required this.quantity,
    required this.entryPrice,
    required this.costBasis,
    required this.entryCount,
    this.openedAt,
  });

  factory BotPosition.fromJson(Map<String, dynamic> json) => BotPosition(
    quantity: _d(json['quantity'], 0),
    entryPrice: _d(json['entry_price'], 0),
    costBasis: _d(json['cost_basis'], 0),
    entryCount: _i(json['entry_count'], 0),
    openedAt: _dt(json['opened_at']),
  );
}

/// บอทหนึ่งตัว
class AiBot {
  final int id;
  final String name;
  final String pair;
  final String strategy;
  final String strategyName;
  final String strategyNameTh;

  /// ⚠️ ความเสี่ยงประจำตัวของ **กลยุทธ์** ไม่ใช่ความเสี่ยงตลาดตอนนี้
  /// (ตัวนั้นอยู่ที่ [lastRiskLevel]) — เอามาทาสีปนกันแล้วผู้ใช้สับสนแน่
  final String riskLevel;

  final String timeframe;
  final Map<String, dynamic> params;
  final BotRisk risk;
  final String status; // draft | running | paused | stopped
  final bool banned;
  final String? bannedReason;
  final String mode; // demo | live
  final Map<String, dynamic> stats;
  final DateTime? lastRunAt;
  final DateTime? lastSignalAt;
  final String? lastReason;
  final BotPosition? position;
  final DateTime? createdAt;

  const AiBot({
    required this.id,
    required this.name,
    required this.pair,
    required this.strategy,
    required this.strategyName,
    required this.strategyNameTh,
    required this.riskLevel,
    required this.timeframe,
    required this.params,
    required this.risk,
    required this.status,
    required this.banned,
    this.bannedReason,
    required this.mode,
    required this.stats,
    this.lastRunAt,
    this.lastSignalAt,
    this.lastReason,
    this.position,
    this.createdAt,
  });

  factory AiBot.fromJson(Map<String, dynamic> json) {
    final strategy = _s(json['strategy']);
    final sName = _s(json['strategy_name'], strategy);
    final pos = _map(json['position']);
    return AiBot(
      id: _i(json['id'], 0),
      name: _s(json['name'], '—'),
      pair: _s(json['pair']),
      strategy: strategy,
      strategyName: sName,
      strategyNameTh: _s(json['strategy_name_th'], sName),
      riskLevel: _s(json['risk_level'], 'medium'),
      timeframe: _s(json['timeframe'], '1h'),
      params: _map(json['params']) ?? const {},
      risk: BotRisk.fromJson(_map(json['risk'])),
      status: _s(json['status'], 'paused'),
      banned: json['banned'] == true,
      bannedReason: _sn(json['banned_reason']),
      mode: _s(json['mode'], 'demo'),
      stats: _map(json['stats']) ?? const {},
      lastRunAt: _dt(json['last_run_at']),
      lastSignalAt: _dt(json['last_signal_at']),
      lastReason: _sn(json['last_reason']),
      position: pos == null ? null : BotPosition.fromJson(pos),
      createdAt: _dt(json['created_at']),
    );
  }

  bool get isRunning => status == 'running';
  bool get isPaused => status == 'paused';
  bool get isStopped => status == 'stopped';
  bool get isLive => mode == 'live';

  /// นับเข้าโควตาไหม — `stopped` ไม่นับ แต่ `paused` **นับ**
  /// ผู้ใช้ที่ชนโควตาต้องกด "หยุด" ไม่ใช่ "พัก"
  bool get countsTowardQuota => isRunning || isPaused;

  /// `{}` ก่อนบอทเดินรอบแรก — จึงเป็น null ได้
  String? get lastAction => _sn(stats['last_action']);

  /// ความเสี่ยงตลาดรอบล่าสุดที่บอทเห็น
  String? get lastRiskLevel => _sn(stats['last_risk']);

  /// เดินมาแล้วกี่นาที — null ถ้าไม่ได้ running หรือยังไม่เคยเดิน
  int? get minutesSinceRun {
    if (!isRunning) return null;
    final at = lastRunAt;
    if (at == null) return null;
    final diff = DateTime.now().difference(at).inMinutes;
    return diff < 0 ? 0 : diff;
  }

  /// บอทบอกว่า running แต่เงียบเกินสองเท่าของรอบที่ช้าที่สุด (5 นาที)
  ///
  /// ไฟเขียวต้องหมายถึง "เดินอยู่จริง" ไม่ใช่ "ผู้ใช้กดเปิดไว้" —
  /// ตัวจับเวลาฝั่งเซิร์ฟเวอร์เคยตายเงียบมาแล้ว
  bool get isStale {
    final m = minutesSinceRun;
    return m != null && m >= 10;
  }

  /// โหมดจริงเสนอสัญญาณไว้ รอผู้ใช้กดยืนยันเอง
  bool get awaitingConfirm => (lastReason ?? '').startsWith('[รอยืนยัน]');

  /// เหตุผลรอบล่าสุดแบบตัดคำนำหน้า `[รอยืนยัน]` ออก
  String? get cleanReason {
    final r = lastReason;
    if (r == null) return null;
    return r.replaceFirst('[รอยืนยัน] ', '');
  }

  String strategyLabel(bool isThai) => isThai ? strategyNameTh : strategyName;

  /// ใช้กับ optimistic update — เปลี่ยนเฉพาะที่ระบุ
  AiBot copyWith({String? status, String? mode}) => AiBot(
    id: id,
    name: name,
    pair: pair,
    strategy: strategy,
    strategyName: strategyName,
    strategyNameTh: strategyNameTh,
    riskLevel: riskLevel,
    timeframe: timeframe,
    params: params,
    risk: risk,
    status: status ?? this.status,
    banned: banned,
    bannedReason: bannedReason,
    mode: mode ?? this.mode,
    stats: stats,
    lastRunAt: lastRunAt,
    lastSignalAt: lastSignalAt,
    lastReason: lastReason,
    position: position,
    createdAt: createdAt,
  );
}

/// สถานะรวมของกระเป๋า — หัวใจของหน้ามอนิเตอร์
class AiBotStatus {
  final String walletAddress;
  final double credits;

  /// ⚠️ = "มี subscription อยู่" — **แพลนฟรีก็ true**
  /// ห้ามใช้ตัวนี้แปลว่า "เช่าแบบเสียเงินแล้ว" ให้ดู `subscription.isFree`
  final bool isActive;

  final AiBotSubscription? subscription;
  final AiBotQuota quota;
  final List<String> unlockedStrategies;
  final bool isAdmin;
  final List<AiBot> bots;

  const AiBotStatus({
    required this.walletAddress,
    required this.credits,
    required this.isActive,
    this.subscription,
    required this.quota,
    required this.unlockedStrategies,
    required this.isAdmin,
    required this.bots,
  });

  factory AiBotStatus.fromJson(Map<String, dynamic> json) {
    final sub = _map(json['subscription']);
    return AiBotStatus(
      walletAddress: _s(json['wallet_address']),
      credits: _d(json['credits'], 0),
      isActive: json['is_active'] == true,
      subscription: sub == null ? null : AiBotSubscription.fromJson(sub),
      quota: AiBotQuota.fromJson(_map(json['quota'])),
      unlockedStrategies: _strList(json['unlocked_strategies']),
      isAdmin: json['is_admin'] == true,
      bots: _mapList(json['bots']).map(AiBot.fromJson).toList(growable: false),
    );
  }

  /// เช่าแบบเสียเงินอยู่จริงไหม
  bool get isPaidPlan => subscription != null && !subscription!.isFree;

  /// เซิร์ฟเวอร์เดินบอทให้ไหม
  bool get runsInCloud => subscription?.runsInCloud == true;

  List<AiBot> get runningBots =>
      bots.where((b) => b.isRunning).toList(growable: false);

  /// บอทที่แอพต้องสั่งเดินเอง (แพลนฟรี)
  List<AiBot> get tickableBots => runsInCloud
      ? const []
      : bots.where((b) => b.isRunning && !b.banned).toList(growable: false);

  AiBot? bot(int id) {
    for (final b in bots) {
      if (b.id == id) return b;
    }
    return null;
  }

  /// แทนที่บอทหนึ่งตัว (ใช้กับ optimistic update)
  AiBotStatus withBot(AiBot updated) => AiBotStatus(
    walletAddress: walletAddress,
    credits: credits,
    isActive: isActive,
    subscription: subscription,
    quota: quota,
    unlockedStrategies: unlockedStrategies,
    isAdmin: isAdmin,
    bots: bots
        .map((b) => b.id == updated.id ? updated : b)
        .toList(growable: false),
  );

  /// กลยุทธ์นี้ปลดล็อกตามแพลนไหม (ยังต้องเช็ค `strategy.available` ซ้ำ)
  bool isUnlocked(String code) => unlockedStrategies.contains(code);
}

// ══════════════════════════════════════════════════════════════════
// พอร์ตทดลอง
// ══════════════════════════════════════════════════════════════════

class DemoAccount {
  final double balance;
  final double startingBalance;

  /// ⚠️ อ่านจากพอร์ตใบแรกเท่านั้น อาจต่ำกว่าที่ใช้ไปจริง — ถือเป็นค่าประมาณ
  final int resetsUsedToday;
  final int resetsPerDay;

  /// เปอร์เซ็นต์ต่อไม้ (0.1 = 0.1%) ไม่ใช่ทศนิยม — แสดงผลต้องต่อท้าย %
  final double feeRate;
  final int slippageBps;

  const DemoAccount({
    required this.balance,
    required this.startingBalance,
    required this.resetsUsedToday,
    required this.resetsPerDay,
    required this.feeRate,
    required this.slippageBps,
  });

  factory DemoAccount.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return DemoAccount(
      balance: _d(m['balance'], 0),
      startingBalance: _d(m['starting_balance'], 0),
      resetsUsedToday: _i(m['resets_used_today'], 0),
      resetsPerDay: _i(m['resets_per_day'], 0),
      feeRate: _d(m['fee_rate'], 0.1),
      slippageBps: _i(m['slippage_bps'], 8),
    );
  }

  int get resetsLeft {
    final left = resetsPerDay - resetsUsedToday;
    return left < 0 ? 0 : left;
  }

  /// ต้นทุนไปกลับต่อรอบ (bps) — ใช้อธิบายว่าทำไม scalping ถึงต้องตั้งเป้าสูง
  double get roundTripBps => 2 * (feeRate * 100 + slippageBps);
}

class DemoPosition {
  final int id;
  final int botId;
  final String? botName;
  final String pair;
  final double quantity;
  final double entryPrice;
  final double costBasis;
  final int entryCount;
  final DateTime? openedAt;
  final double? currentPrice;
  final double marketValue;
  final double unrealizedPnl;
  final double unrealizedPct;

  /// false ⇒ ตีราคาไม่ได้ ⇒ [marketValue]/[unrealizedPnl]/[unrealizedPct]
  /// **ไม่จริง** ต้องขึ้นป้าย "ยังตีราคาไม่ได้" แทนตัวเลข
  final bool priced;

  const DemoPosition({
    required this.id,
    required this.botId,
    this.botName,
    required this.pair,
    required this.quantity,
    required this.entryPrice,
    required this.costBasis,
    required this.entryCount,
    this.openedAt,
    this.currentPrice,
    required this.marketValue,
    required this.unrealizedPnl,
    required this.unrealizedPct,
    required this.priced,
  });

  factory DemoPosition.fromJson(Map<String, dynamic> json) => DemoPosition(
    id: _i(json['id'], 0),
    botId: _i(json['bot_id'], 0),
    botName: _sn(json['bot_name']),
    pair: _s(json['pair']),
    quantity: _d(json['quantity'], 0),
    entryPrice: _d(json['entry_price'], 0),
    costBasis: _d(json['cost_basis'], 0),
    entryCount: _i(json['entry_count'], 0),
    openedAt: _dt(json['opened_at']),
    currentPrice: _dn(json['current_price']),
    marketValue: _d(json['market_value'], 0),
    unrealizedPnl: _d(json['unrealized_pnl'], 0),
    unrealizedPct: _d(json['unrealized_pct'], 0),
    priced: json['priced'] == true,
  );
}

class DemoTrade {
  final int id;
  final int botId;
  final String pair;
  final String side; // buy | sell
  final double price;
  final double quantity;
  final double grossValue;
  final double fee;
  final double slippageCost;

  /// **null ทุกไม้ buy** — มีค่าเฉพาะไม้ที่ปิดแล้ว
  final double? realizedPnl;

  final String strategy;
  final String reason;
  final String riskLevel;
  final DateTime? createdAt;

  const DemoTrade({
    required this.id,
    required this.botId,
    required this.pair,
    required this.side,
    required this.price,
    required this.quantity,
    required this.grossValue,
    required this.fee,
    required this.slippageCost,
    this.realizedPnl,
    required this.strategy,
    required this.reason,
    required this.riskLevel,
    this.createdAt,
  });

  factory DemoTrade.fromJson(Map<String, dynamic> json) => DemoTrade(
    id: _i(json['id'], 0),
    botId: _i(json['bot_id'], 0),
    pair: _s(json['pair']),
    side: _s(json['side'], 'buy'),
    price: _d(json['price'], 0),
    quantity: _d(json['quantity'], 0),
    grossValue: _d(json['gross_value'], 0),
    fee: _d(json['fee'], 0),
    slippageCost: _d(json['slippage_cost'], 0),
    realizedPnl: _dn(json['realized_pnl']),
    strategy: _s(json['strategy']),
    reason: _s(json['reason']),
    riskLevel: _s(json['risk_level'], 'calm'),
    createdAt: _dt(json['created_at']),
  );

  bool get isBuy => side == 'buy';
  bool get isClosed => realizedPnl != null;
}

/// พอร์ตแยกรายกลยุทธ์ — คำตอบของ "กลยุทธ์ไหนดีกว่า"
class DemoPortfolio {
  /// null = พอร์ตรวมของเก่า ไม่ใช่ error
  final String? strategy;
  final double balance;
  final double startingBalance;
  final double pnl;

  const DemoPortfolio({
    this.strategy,
    required this.balance,
    required this.startingBalance,
    required this.pnl,
  });

  factory DemoPortfolio.fromJson(Map<String, dynamic> json) => DemoPortfolio(
    strategy: _sn(json['strategy']),
    balance: _d(json['balance'], 0),
    startingBalance: _d(json['starting_balance'], 0),
    pnl: _d(json['pnl'], 0),
  );

  double get pnlPct => startingBalance > 0 ? (pnl / startingBalance) * 100 : 0;
}

class DemoSummary {
  final double realizedPnl;
  final double unrealizedPnl;
  final double positionsValue;

  /// ⚠️ ของเซิร์ฟเวอร์ใช้ balance ของพอร์ตใบแรกใบเดียว ไม่ตรงกับ
  /// `account.balance` ที่รวมทุกใบ — อย่าโชว์ตรงๆ ใช้ [AiBotDemo.equity]
  final double serverEquity;

  final double totalFees;
  final int tradeCount;
  final int closedCount;
  final int wins;
  final int losses;

  /// null เมื่อยังไม่มีไม้ปิด — ต้องแสดง "—" ไม่ใช่ 0%
  final double? winRate;

  const DemoSummary({
    required this.realizedPnl,
    required this.unrealizedPnl,
    required this.positionsValue,
    required this.serverEquity,
    required this.totalFees,
    required this.tradeCount,
    required this.closedCount,
    required this.wins,
    required this.losses,
    this.winRate,
  });

  factory DemoSummary.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return DemoSummary(
      realizedPnl: _d(m['realized_pnl'], 0),
      unrealizedPnl: _d(m['unrealized_pnl'], 0),
      positionsValue: _d(m['positions_value'], 0),
      serverEquity: _d(m['equity'], 0),
      totalFees: _d(m['total_fees'], 0),
      tradeCount: _i(m['trade_count'], 0),
      closedCount: _i(m['closed_count'], 0),
      wins: _i(m['wins'], 0),
      losses: _i(m['losses'], 0),
      winRate: _dn(m['win_rate']),
    );
  }
}

class AiBotDemo {
  final DemoAccount account;
  final List<DemoPosition> positions;
  final List<DemoTrade> trades;
  final List<DemoPortfolio> portfolios;
  final DemoSummary summary;

  const AiBotDemo({
    required this.account,
    required this.positions,
    required this.trades,
    required this.portfolios,
    required this.summary,
  });

  factory AiBotDemo.fromJson(Map<String, dynamic> json) => AiBotDemo(
    account: DemoAccount.fromJson(_map(json['account'])),
    positions: _mapList(
      json['positions'],
    ).map(DemoPosition.fromJson).toList(growable: false),
    trades: _mapList(
      json['trades'],
    ).map(DemoTrade.fromJson).toList(growable: false),
    portfolios: _mapList(
      json['portfolios'],
    ).map(DemoPortfolio.fromJson).toList(growable: false),
    summary: DemoSummary.fromJson(_map(json['summary'])),
  );

  /// มูลค่าพอร์ตรวมที่ **ตรงกับตัวเลขอื่นบนจอ**
  ///
  /// คำนวณเองแทน `summary.equity` เพราะของเซิร์ฟเวอร์รวมพอร์ตไม่ครบ
  /// เมื่อมีหลายกลยุทธ์ ทำให้เลขสองจุดบนจอเดียวกันไม่ตรงกัน
  double get equity => account.balance + summary.positionsValue;

  /// ผลรวมที่ผู้ใช้ควรใช้ตัดสินใจ — ปิดแล้ว + ที่ยังลอยอยู่
  double get totalPnl => summary.realizedPnl + summary.unrealizedPnl;

  /// % ของผลที่เกิดขึ้นจริง (ใช้กำไรที่ปิดแล้วเป็นตัวตั้ง ตามแบบของเว็บ)
  double get realizedPnlPct => account.startingBalance > 0
      ? (summary.realizedPnl / account.startingBalance) * 100
      : 0;

  /// มีไม้ที่ตีราคาไม่ได้ปนอยู่ไหม (ต้องเตือนว่าตัวเลขยังไม่ครบ)
  bool get hasUnpriced => positions.any((p) => !p.priced);
}

// ══════════════════════════════════════════════════════════════════
// สถิติย้อนหลัง
// ══════════════════════════════════════════════════════════════════

class AnalyticsSummary {
  final String? key;
  final int trades;
  final int closed;
  final int wins;
  final int losses;
  final double? winRate;
  final double realizedPnl;
  final double? avgPnl;
  final double? bestTrade;
  final double? worstTrade;
  final double totalFees;
  final double totalSlippage;
  final double totalCost;

  /// null มีสองความหมาย — ยังไม่มีไม้ปิดเลย (closed = 0) หรือ มีแต่ไม้กำไร
  /// **ห้ามแปลว่า "ดีเลิศ"** ต้องดู [closed] ประกอบเสมอ
  final double? profitFactor;

  final double? expectancy;

  /// บวกเสมอ (เป็นขนาดของการขาดทุน)
  final double? maxDrawdown;

  final DateTime? firstTradeAt;
  final DateTime? lastTradeAt;

  const AnalyticsSummary({
    this.key,
    required this.trades,
    required this.closed,
    required this.wins,
    required this.losses,
    this.winRate,
    required this.realizedPnl,
    this.avgPnl,
    this.bestTrade,
    this.worstTrade,
    required this.totalFees,
    required this.totalSlippage,
    required this.totalCost,
    this.profitFactor,
    this.expectancy,
    this.maxDrawdown,
    this.firstTradeAt,
    this.lastTradeAt,
  });

  factory AnalyticsSummary.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return AnalyticsSummary(
      key: _sn(m['key']),
      trades: _i(m['trades'], 0),
      closed: _i(m['closed'], 0),
      wins: _i(m['wins'], 0),
      losses: _i(m['losses'], 0),
      winRate: _dn(m['win_rate']),
      realizedPnl: _d(m['realized_pnl'], 0),
      avgPnl: _dn(m['avg_pnl']),
      bestTrade: _dn(m['best_trade']),
      worstTrade: _dn(m['worst_trade']),
      totalFees: _d(m['total_fees'], 0),
      totalSlippage: _d(m['total_slippage'], 0),
      totalCost: _d(m['total_cost'], 0),
      profitFactor: _dn(m['profit_factor']),
      expectancy: _dn(m['expectancy']),
      maxDrawdown: _dn(m['max_drawdown']),
      firstTradeAt: _dt(m['first_trade_at']),
      lastTradeAt: _dt(m['last_trade_at']),
    );
  }

  /// มีข้อมูลพอจะตัดสินอะไรได้หรือยัง
  bool get canJudge => closed > 0;

  /// profit factor ตัดสินไม่ได้ (ยังไม่มีไม้ขาดทุนให้เทียบ)
  bool get profitFactorUnknown => profitFactor == null;
}

class AiBotAnalytics {
  final AnalyticsSummary overall;
  final List<AnalyticsSummary> byStrategy;
  final List<AnalyticsSummary> byPair;
  final List<AnalyticsSummary> byRisk;

  const AiBotAnalytics({
    required this.overall,
    required this.byStrategy,
    required this.byPair,
    required this.byRisk,
  });

  factory AiBotAnalytics.fromJson(Map<String, dynamic> json) => AiBotAnalytics(
    overall: AnalyticsSummary.fromJson(_map(json['overall'])),
    byStrategy: _mapList(
      json['by_strategy'],
    ).map(AnalyticsSummary.fromJson).toList(growable: false),
    byPair: _mapList(
      json['by_pair'],
    ).map(AnalyticsSummary.fromJson).toList(growable: false),
    byRisk: _mapList(
      json['by_risk'],
    ).map(AnalyticsSummary.fromJson).toList(growable: false),
  );

  bool get isEmpty => overall.trades == 0;
}

// ══════════════════════════════════════════════════════════════════
// มุมมองตลาดของ AI
// ══════════════════════════════════════════════════════════════════

class CoinView {
  final String symbol;
  final double score;
  final String stance; // buy | hold | avoid | exit
  final String why;

  const CoinView({
    required this.symbol,
    required this.score,
    required this.stance,
    required this.why,
  });

  /// ค่าดิบจาก LLM — ฟิลด์อาจขาดได้ทุกตัว ต้อง parse แบบทนพัง
  factory CoinView.fromEntry(String symbol, dynamic raw) {
    final m = _map(raw) ?? const {};
    return CoinView(
      symbol: symbol,
      score: _d(m['score'], 0),
      stance: _s(m['stance'], 'hold'),
      why: _s(m['why']),
    );
  }
}

class AiMarketViewBody {
  final String scope; // tactical | strategic
  final String? regime;
  final double confidence; // 0..1
  final double sizeMultiplier;
  final String? summary;
  final List<CoinView> coins; // เรียงคะแนนมาก→น้อยแล้ว
  final List<String> shortlist;
  final List<String> headlines;
  final String? model;
  final DateTime? createdAt;
  final DateTime? expiresAt;

  const AiMarketViewBody({
    required this.scope,
    this.regime,
    required this.confidence,
    required this.sizeMultiplier,
    this.summary,
    required this.coins,
    required this.shortlist,
    required this.headlines,
    this.model,
    this.createdAt,
    this.expiresAt,
  });

  factory AiMarketViewBody.fromJson(Map<String, dynamic> json) {
    // `coins` เป็น map (คีย์ = สัญลักษณ์) ไม่ใช่ list — cast ตรงๆ ไม่ได้
    final rawCoins = _map(json['coins']) ?? const {};
    final coins =
        rawCoins.entries.map((e) => CoinView.fromEntry(e.key, e.value)).toList()
          ..sort((a, b) => b.score.compareTo(a.score));

    return AiMarketViewBody(
      scope: _s(json['scope'], 'tactical'),
      regime: _sn(json['regime']),
      confidence: _d(json['confidence'], 0),
      sizeMultiplier: _d(json['size_multiplier'], 1),
      summary: _sn(json['summary']),
      coins: coins,
      shortlist: _strList(json['shortlist']),
      headlines: _headlines(json['headlines']),
      model: _sn(json['model']),
      createdAt: _dt(json['created_at']),
      expiresAt: _dt(json['expires_at']),
    );
  }

  int get confidencePct => (confidence * 100).round();

  int? get ageMinutes {
    final at = createdAt;
    if (at == null) return null;
    final m = DateTime.now().difference(at).inMinutes;
    return m < 0 ? 0 : m;
  }

  bool get expiringSoon {
    final at = expiresAt;
    if (at == null) return false;
    return at.difference(DateTime.now()).inMinutes < 15;
  }
}

/// ซองของ `/market-view` — 3 รูปร่าง (ปิดระบบ / เปิดแต่ไม่มีมุมมอง / มีมุมมอง)
class AiMarketView {
  final bool enabled;

  /// AI คิดแต่ยังไม่มีผลต่อการเทรด — มีเฉพาะเมื่อ [view] ไม่ null
  final bool shadow;

  /// เหตุผลที่ยังไม่มีมุมมอง — มีเฉพาะเมื่อ [view] เป็น null
  final String? reason;

  final AiMarketViewBody? view;

  const AiMarketView({
    required this.enabled,
    required this.shadow,
    this.reason,
    this.view,
  });

  factory AiMarketView.fromJson(Map<String, dynamic> json) {
    final v = _map(json['view']);
    return AiMarketView(
      enabled: json['enabled'] == true,
      shadow: json['shadow'] == true,
      reason: _sn(json['reason']),
      view: v == null ? null : AiMarketViewBody.fromJson(v),
    );
  }

  /// เปิดระบบแล้วแต่ยังไม่มีมุมมอง — ต้องโชว์ [reason] ห้ามซ่อนแผงเงียบๆ
  bool get isEmptyButOn => enabled && view == null;
}

// ══════════════════════════════════════════════════════════════════
// ด่านความเสี่ยง
// ══════════════════════════════════════════════════════════════════

class NewsHeadline {
  final String title;
  final String source;
  final String url;
  final double panicScore;
  final DateTime? publishedAt;

  const NewsHeadline({
    required this.title,
    required this.source,
    required this.url,
    required this.panicScore,
    this.publishedAt,
  });

  factory NewsHeadline.fromJson(Map<String, dynamic> json) => NewsHeadline(
    title: _s(json['title']),
    source: _s(json['source']),
    url: _s(json['url']),
    panicScore: _d(json['panic_score'], 0),
    publishedAt: _dt(json['published_at']),
  );

  int get panicPct => (panicScore * 100).round();
}

class MarketRisk {
  final double score;

  /// ⚠️ **หายไปทั้งคีย์** เมื่อ [available] เป็น false — ไม่ใช่ null ธรรมดา
  final double? change1h;
  final double? change24h;

  final List<String> reasons;
  final bool available;

  const MarketRisk({
    required this.score,
    this.change1h,
    this.change24h,
    required this.reasons,
    required this.available,
  });

  factory MarketRisk.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return MarketRisk(
      score: _d(m['score'], 0),
      change1h: _dn(m['change_1h']),
      change24h: _dn(m['change_24h']),
      reasons: _strList(m['reasons']),
      available: m['available'] == true,
    );
  }
}

class NewsRisk {
  final double score;
  final List<NewsHeadline> headlines;
  final List<String> reasons;
  final int count;
  final int totalRecent;

  /// ⚠️ MySQL datetime ดิบ ไม่มี timezone — เป็นเวลาของเซิร์ฟเวอร์
  /// อย่าเรียก `.toLocal()` ตรงๆ จะเลื่อนผิด
  final DateTime? lastIngestedAt;

  const NewsRisk({
    required this.score,
    required this.headlines,
    required this.reasons,
    required this.count,
    required this.totalRecent,
    this.lastIngestedAt,
  });

  factory NewsRisk.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const {};
    return NewsRisk(
      score: _d(m['score'], 0),
      headlines: _mapList(
        m['headlines'],
      ).map(NewsHeadline.fromJson).toList(growable: false),
      reasons: _strList(m['reasons']),
      count: _i(m['count'], 0),
      totalRecent: _i(m['total_recent'], 0),
      lastIngestedAt: _dt(m['last_ingested_at']),
    );
  }

  /// ข่าวเงียบจริง หรือ ตัวดึงข่าวพัง — แยกกันได้ด้วย total_recent
  bool get feedLooksDead => totalRecent == 0;
}

class AiRiskView {
  /// คู่ที่ประเมิน — เซิร์ฟเวอร์ไม่ส่งกลับมาในซอง ผู้เรียกเติมเองด้วย [forPair]
  final String pair;

  final String level; // calm | caution | elevated | panic
  final double score; // 0..1
  final double sizeMultiplier;
  final bool forceExit;

  /// ⚠️ false ⇒ ประเมินจากราคาไม่ได้ **ห้ามวาด "สงบ 0%"**
  /// ค่าปริยายคือคะแนน 0 ถ้าวาดตามนั้น = ยืนยันความปลอดภัยให้ผู้ใช้
  /// ก่อนจ่ายเงิน ทั้งที่ระบบไม่รู้อะไรเลย
  final bool available;

  final MarketRisk market;
  final NewsRisk news;
  final List<String> reasons;

  const AiRiskView({
    this.pair = '',
    required this.level,
    required this.score,
    required this.sizeMultiplier,
    required this.forceExit,
    required this.available,
    required this.market,
    required this.news,
    required this.reasons,
  });

  /// [pair] ไม่ได้อยู่ในซองของเซิร์ฟเวอร์ — ผู้เรียกเป็นคนบอกว่าถามคู่ไหนไป
  factory AiRiskView.fromJson(Map<String, dynamic> json, [String pair = '']) =>
      AiRiskView(
        pair: pair,
        level: _s(json['level'], 'calm'),
        score: _d(json['score'], 0),
        sizeMultiplier: _d(json['size_multiplier'], 1),
        forceExit: json['force_exit'] == true,
        available: json['available'] == true,
        market: MarketRisk.fromJson(_map(json['market'])),
        news: NewsRisk.fromJson(_map(json['news'])),
        reasons: _strList(json['reasons']),
      );

  int get scorePct => (score * 100).round();
  int get sizePct => (sizeMultiplier * 100).round();

  /// ติดชื่อคู่ให้ผลลัพธ์ (ซองของเซิร์ฟเวอร์ไม่ได้ส่งคู่กลับมาด้วย)
  AiRiskView forPair(String value) => AiRiskView(
    pair: value,
    level: level,
    score: score,
    sizeMultiplier: sizeMultiplier,
    forceExit: forceExit,
    available: available,
    market: market,
    news: news,
    reasons: reasons,
  );
}

// ══════════════════════════════════════════════════════════════════
// ที่ปรึกษา / เครดิต / การเติมเงิน
// ══════════════════════════════════════════════════════════════════

/// คำแนะนำจาก AI — ล้มเหลวก็ยังเป็น HTTP 200 เสมอ ต้องดู [ok]
class AiAdvice {
  final bool ok;
  final String provider; // gemini | openai | null
  final String text;

  /// มีเฉพาะเมื่อ [ok] เป็น false
  final String? reason;

  const AiAdvice({
    required this.ok,
    required this.provider,
    required this.text,
    this.reason,
  });

  factory AiAdvice.fromJson(Map<String, dynamic> json) => AiAdvice(
    ok: json['ok'] == true,
    provider: _s(json['provider'], 'null'),
    text: _s(json['text']),
    reason: _sn(json['reason']),
  );

  /// ยังไม่ได้ตั้งคีย์ผู้ให้บริการ — เป็นสถานะปกติของ prod ตอนนี้ ไม่ใช่ error
  bool get providerMissing => !ok && provider == 'null';
}

/// หนึ่งรายการในบัญชีเครดิต
class CreditEntry {
  final int id;
  final String type; // topup | charge | refund | bonus | adjustment
  final double amount; // ติดลบสำหรับ charge
  final double balanceAfter;
  final String? reference;
  final DateTime? createdAt;

  const CreditEntry({
    required this.id,
    required this.type,
    required this.amount,
    required this.balanceAfter,
    this.reference,
    this.createdAt,
  });

  factory CreditEntry.fromJson(Map<String, dynamic> json) => CreditEntry(
    id: _i(json['id'], 0),
    type: _s(json['type']),
    amount: _d(json['amount'], 0),
    balanceAfter: _d(json['balance_after'], 0),
    reference: _sn(json['reference']),
    createdAt: _dt(json['created_at']),
  );

  bool get isCredit => amount >= 0;
}

class CreditLedger {
  final double balance;
  final List<CreditEntry> entries;

  const CreditLedger({required this.balance, required this.entries});

  factory CreditLedger.fromJson(Map<String, dynamic> json) => CreditLedger(
    balance: _d(json['balance'], 0),
    entries: _mapList(
      json['entries'],
    ).map(CreditEntry.fromJson).toList(growable: false),
  );
}

/// ใบแจ้งความจำนงเติมเครดิต — **ไม่ได้เติมให้ทันที** ต้องมีคนยืนยันหลังบ้าน
class TopupRequest {
  final String reference;
  final CreditPack? pack;
  final String currency;
  final String status; // ตอนนี้มีค่าเดียวเสมอ: pending_payment

  const TopupRequest({
    required this.reference,
    this.pack,
    required this.currency,
    required this.status,
  });

  factory TopupRequest.fromJson(Map<String, dynamic> json) {
    final p = _map(json['pack']);
    return TopupRequest(
      reference: _s(json['reference']),
      pack: p == null ? null : CreditPack.fromJson(p),
      currency: _s(json['currency'], 'TPIX'),
      status: _s(json['status'], 'pending_payment'),
    );
  }
}

// ══════════════════════════════════════════════════════════════════
// ประวัติการตัดสินใจ (หัวใจของการมอนิเตอร์)
// ══════════════════════════════════════════════════════════════════

/// หนึ่งรอบความคิดของบอท — รวมรอบที่ตัดสินใจ "ไม่ทำอะไร"
///
/// เหตุผลที่บอทไม่ทำอะไรคือสิ่งที่บอกได้ว่าบอททำงานถูกหรือเปล่า
/// และเกิดบ่อยกว่าการเข้าไม้หลายสิบเท่า
class AiBotDecision {
  final int id;
  final int botId;
  final String? botName;
  final String pair;
  final String strategy;
  final String strategyName;
  final String strategyNameTh;
  final String timeframe;
  final String mode;
  final String action; // buy | sell | hold | signal | stopped | error
  final String reason;
  final String riskLevel;
  final double? price;
  final double? budget;
  final bool hasPosition;
  final Map<String, dynamic>? signalMeta;
  final DateTime? createdAt;

  const AiBotDecision({
    required this.id,
    required this.botId,
    this.botName,
    required this.pair,
    required this.strategy,
    required this.strategyName,
    required this.strategyNameTh,
    required this.timeframe,
    required this.mode,
    required this.action,
    required this.reason,
    required this.riskLevel,
    this.price,
    this.budget,
    required this.hasPosition,
    this.signalMeta,
    this.createdAt,
  });

  factory AiBotDecision.fromJson(Map<String, dynamic> json) {
    final strategy = _s(json['strategy']);
    final sName = _s(json['strategy_name'], strategy);
    return AiBotDecision(
      id: _i(json['id'], 0),
      botId: _i(json['bot_id'], 0),
      botName: _sn(json['bot_name']),
      pair: _s(json['pair']),
      strategy: strategy,
      strategyName: sName,
      strategyNameTh: _s(json['strategy_name_th'], sName),
      timeframe: _s(json['timeframe']),
      mode: _s(json['mode'], 'demo'),
      action: _s(json['action'], 'hold'),
      reason: _s(json['reason']),
      riskLevel: _s(json['risk_level'], 'calm'),
      price: _dn(json['price']),
      budget: _dn(json['budget']),
      hasPosition: json['has_position'] == true,
      signalMeta: _map(json['signal_meta']),
      createdAt: _dt(json['created_at']),
    );
  }

  /// ลงมือจริงไหม (ไม่ใช่แค่คิดแล้วผ่าน)
  bool get acted => action == 'buy' || action == 'sell';

  bool get isError => action == 'error';

  String strategyLabel(bool isThai) => isThai ? strategyNameTh : strategyName;
}

/// หนึ่งหน้าของประวัติการตัดสินใจ (เลื่อนด้วย cursor ไม่ใช่เลขหน้า)
class DecisionPage {
  final List<AiBotDecision> decisions;
  final int? nextCursor;
  final bool hasMore;

  const DecisionPage({
    required this.decisions,
    this.nextCursor,
    required this.hasMore,
  });

  factory DecisionPage.fromJson(Map<String, dynamic> json) => DecisionPage(
    decisions: _mapList(
      json['decisions'],
    ).map(AiBotDecision.fromJson).toList(growable: false),
    nextCursor: _in(json['next_cursor']),
    hasMore: json['has_more'] == true,
  );

  static const DecisionPage empty = DecisionPage(
    decisions: [],
    nextCursor: null,
    hasMore: false,
  );
}

// ══════════════════════════════════════════════════════════════════
// การสั่งบอทเดินหนึ่งรอบ (แพลนฟรี)
// ══════════════════════════════════════════════════════════════════

/// ผลของ `/bots/{id}/tick` — 2 รูปร่าง
class TickOutcome {
  /// true = ยังไม่ถึงรอบ (ไม่ใช่ความล้มเหลว)
  final bool skipped;

  final String reason;

  /// เหลืออีกกี่วินาทีถึงรอบถัดไป — มีเฉพาะเมื่อ [skipped]
  final int? nextInSeconds;

  /// มีเฉพาะเมื่อเดินจริง
  final String? action;
  final String? riskLevel;
  final AiBot? bot;

  const TickOutcome({
    required this.skipped,
    required this.reason,
    this.nextInSeconds,
    this.action,
    this.riskLevel,
    this.bot,
  });

  factory TickOutcome.fromJson(Map<String, dynamic> json) {
    final skipped = json['skipped'] == true;
    final b = _map(json['bot']);
    return TickOutcome(
      skipped: skipped,
      reason: _s(json['reason']),
      nextInSeconds: _in(json['next_in_seconds']),
      action: _sn(json['action']),
      riskLevel: _sn(json['risk']),
      bot: b == null ? null : AiBot.fromJson(b),
    );
  }
}

/// บันทึกหนึ่งบรรทัดของลูปเดินบอทในแอพ (ไว้โชว์ให้ผู้ใช้เห็นว่ามันเดินอยู่จริง)
class AiBotTickLog {
  final DateTime at;
  final int botId;
  final String botName;
  final String action;
  final String reason;

  const AiBotTickLog({
    required this.at,
    required this.botId,
    required this.botName,
    required this.action,
    required this.reason,
  });
}

// ══════════════════════════════════════════════════════════════════
// ข้อความของรหัสข้อผิดพลาด (2 ภาษา)
// ══════════════════════════════════════════════════════════════════

/// แปลรหัสจากเซิร์ฟเวอร์เป็นข้อความที่ผู้ใช้อ่านรู้เรื่องทั้งสองภาษา
///
/// เซิร์ฟเวอร์ส่งข้อความมาเป็น **ภาษาไทยอย่างเดียว** ผู้ใช้ที่ตั้งเป็นอังกฤษ
/// จึงต้องได้ข้อความของแอพเอง และใช้ของเซิร์ฟเวอร์เป็นทางลงเมื่อเจอรหัสแปลกใหม่
class AiBotErrorText {
  AiBotErrorText._();

  static const Map<String, List<String>> _map = {
    // [ไทย, อังกฤษ]
    'NO_WALLET': [
      'เชื่อมกระเป๋าก่อนถึงจะใช้ AI TRADE ได้',
      'Connect your wallet to use AI TRADE',
    ],
    'INVALID_WALLET': [
      'ที่อยู่กระเป๋าไม่ถูกต้อง — ลองเชื่อมกระเป๋าใหม่อีกครั้ง',
      'Wallet address is invalid — try reconnecting your wallet',
    ],
    'WALLET_NOT_VERIFIED': [
      'การยืนยันกระเป๋าหมดอายุแล้ว (มีอายุ 4 ชั่วโมง) — เซ็นยืนยันอีกครั้ง ไม่เสียค่าแก๊ส',
      'Wallet verification expired (it lasts 4 hours) — sign again, no gas needed',
    ],
    'WALLET_IP_MISMATCH': [
      'เครือข่ายเปลี่ยนระหว่างใช้งาน (สลับ WiFi หรือเน็ตมือถือ) — เซ็นยืนยันกระเป๋าอีกครั้ง',
      'Your network changed mid-session (WiFi to mobile data) — please verify your wallet again',
    ],
    'KYC_REQUIRED': [
      'ต้องยืนยันตัวตนก่อนใช้ฟีเจอร์นี้ — ทำได้ที่หน้าเว็บ tpix.online/kyc',
      'Identity verification is required first — complete it at tpix.online/kyc',
    ],
    'PLAN_NOT_FOUND': [
      'ไม่พบแพลนนี้แล้ว — ดึงรายการแพลนใหม่อีกครั้ง',
      'That plan no longer exists — refresh the plan list',
    ],
    'INSUFFICIENT_CREDITS': [
      'เครดิตการทำงานไม่พอ — เติมเครดิตก่อนเริ่มใช้งานบอท',
      'Not enough work credits — top up before starting a bot',
    ],
    'SALES_CLOSED': [
      'ยังไม่เปิดให้เช่าบอท — ระหว่างนี้ใช้โหมดทดลองได้เต็มที่ ไม่มีค่าใช้จ่าย',
      'Bot rentals are not open yet — the demo mode is free to use meanwhile',
    ],
    'SUBSCRIBE_IN_PROGRESS': [
      'กำลังทำรายการเช่าของกระเป๋านี้อยู่ — รอสักครู่แล้วลองใหม่',
      'A rental request for this wallet is already in progress — wait a moment and retry',
    ],
    'TOPUP_UNAVAILABLE': [
      'ยังไม่เปิดให้เติมเครดิต — รอประกาศเปิดระบบชำระเงินก่อน',
      'Credit top-up is not open yet — we will announce it when payments go live',
    ],
    'INVALID_PACK': [
      'ไม่พบแพ็กเกจนี้ — ดึงรายการใหม่อีกครั้ง',
      'That pack was not found — refresh the list',
    ],
    'NO_SUBSCRIPTION': [
      'ต้องเช่าบอทก่อนถึงจะใช้ฟังก์ชันนี้ได้',
      'You need an active rental before using this',
    ],
    'STRATEGY_LOCKED': [
      'กลยุทธ์นี้ต้องใช้แพลนระดับสูงกว่า',
      'This strategy needs a higher plan',
    ],
    'BOT_LIMIT_REACHED': [
      'จำนวนบอทเต็มโควตาของแพลนแล้ว — กด "หยุด" บอทตัวเก่าก่อน (แค่ "พัก" ยังกินโควตาอยู่)',
      'Your plan bot quota is full — Stop an old bot first (Pause still uses the quota)',
    ],
    'PAIR_NO_CANDLES': [
      'คู่นี้ยังไม่มีข้อมูลแท่งเทียนให้บอทใช้ตัดสินใจ — เลือกคู่อื่นก่อน',
      'This pair has no candle data for the bot to work with — pick another pair',
    ],
    'BOT_NOT_FOUND': [
      'ไม่พบบอทตัวนี้แล้ว — อาจถูกลบไปจากอุปกรณ์อื่น',
      'This bot no longer exists — it may have been deleted from another device',
    ],
    'BOT_BANNED': [
      'บอทตัวนี้ถูกทีมงานระงับไว้',
      'This bot has been suspended by the team',
    ],
    'BOT_NOT_RUNNING': [
      'บอทตัวนี้ไม่ได้อยู่ในสถานะทำงาน',
      'This bot is not running',
    ],
    'CLOUD_BOT': [
      'บอทของแพลนนี้เดินบนคลาวด์อยู่แล้ว ไม่ต้องสั่งจากแอพ',
      'Bots on this plan already run in the cloud — no need to drive them from the app',
    ],
    'LIVE_DISABLED': [
      'ตอนนี้เปิดให้ใช้เฉพาะโหมดทดลองก่อน',
      'Only demo mode is open right now',
    ],
    'RESET_LIMIT': [
      'ล้างพอร์ตทดลองได้วันละ 3 ครั้ง — พรุ่งนี้เริ่มใหม่ได้',
      'The demo portfolio can be reset 3 times a day — the quota refills tomorrow',
    ],
    'VALIDATION_ERROR': [
      'ข้อมูลที่กรอกยังไม่ถูกต้อง — ตรวจช่องที่มีข้อความสีแดง',
      'Some fields are not valid — check the ones marked in red',
    ],
    'RATE_LIMITED': [
      'ส่งคำขอถี่เกินไป — พักสักครู่แล้วลองใหม่',
      'Too many requests — pause a moment and try again',
    ],
    'NETWORK': [
      'ต่ออินเทอร์เน็ตไม่ได้ — ตรวจสัญญาณแล้วลองใหม่',
      'No internet connection — check your signal and try again',
    ],
    'TIMEOUT': [
      'เซิร์ฟเวอร์ตอบช้าเกินไป — ลองใหม่อีกครั้ง',
      'The server took too long to respond — please try again',
    ],
    'BAD_PAYLOAD': [
      'เซิร์ฟเวอร์ตอบมาในรูปแบบที่แอพอ่านไม่ได้ — แจ้งทีมงานได้เลย',
      'The server replied in a format the app cannot read — please tell the team',
    ],
  };

  /// ข้อความสำหรับผู้ใช้
  ///
  /// [serverMessage] คือข้อความไทยจากเซิร์ฟเวอร์ ใช้เป็นทางลงเมื่อไม่รู้จักรหัส
  /// — ดีกว่าโชว์รหัสดิบให้ผู้ใช้งง แต่ก็ยังไม่ควรพึ่งเป็นหลักเพราะไม่มีอังกฤษ
  static String of(String code, String? serverMessage, bool isThai) {
    final pair = _map[code];
    if (pair != null) return isThai ? pair[0] : pair[1];

    final msg = serverMessage?.trim();
    if (msg != null && msg.isNotEmpty) return msg;

    // เจอรหัส HTTP_5xx หรืออะไรที่ไม่เคยเห็น
    return isThai
        ? 'ทำรายการไม่สำเร็จ — ลองใหม่อีกครั้ง'
        : 'Something went wrong — please try again';
  }
}

// ══════════════════════════════════════════════════════════════════
// ค่าที่ส่งขึ้นเซิร์ฟเวอร์
// ══════════════════════════════════════════════════════════════════

/// โหมดการทำงานของบอท
enum AiBotMode {
  demo('demo'),
  live('live');

  const AiBotMode(this.wire);

  /// ค่าที่ส่งขึ้น API
  final String wire;

  static AiBotMode fromWire(String? value) =>
      value == 'live' ? AiBotMode.live : AiBotMode.demo;

  AiBotMode get opposite =>
      this == AiBotMode.demo ? AiBotMode.live : AiBotMode.demo;
}

/// คำสั่งเปลี่ยนสถานะบอท
///
/// ⚠️ `stop` เท่านั้นที่ปลดโควตา — `pause` ยังกินโควตาอยู่
/// ผู้ใช้ที่ชนโควตาต้องได้รับคำแนะนำให้กด "หยุด" ไม่ใช่ "พัก"
enum AiBotStateAction {
  start('start'),
  pause('pause'),
  stop('stop');

  const AiBotStateAction(this.wire);

  final String wire;

  /// แปลงจากสตริงที่หน้าจอส่งมา — ค่าที่ไม่รู้จักถือเป็น `pause`
  /// (ปลอดภัยที่สุด: หยุดบอทไว้ก่อน ดีกว่าเผลอสั่งให้เริ่มเดิน)
  static AiBotStateAction fromWire(String? value) => switch (value) {
    'start' => AiBotStateAction.start,
    'stop' => AiBotStateAction.stop,
    _ => AiBotStateAction.pause,
  };

  /// ปลดโควตาบอทไหม — `stop` เท่านั้น
  bool get freesQuota => this == AiBotStateAction.stop;
}

/// ค่าที่กรอกในฟอร์มสร้าง/แก้บอท
///
/// เก็บกรอบความเสี่ยงเป็นตัวเลขแยกช่อง (ไม่ใช่ Map ดิบ) เพื่อให้การประกอบ
/// payload ผ่าน [BotRisk.payload] จุดเดียว — ที่นั่นคือที่ที่กับดัก
/// `max_position_usd_requested` ถูกปิดไว้
class AiBotDraft {
  final String name;
  final String pair;
  final String strategy;
  final String timeframe;
  final Map<String, dynamic> params;
  final double maxPositionUsd;
  final double stopLossPct;
  final double takeProfitPct;
  final double maxDailyLossUsd;

  const AiBotDraft({
    required this.name,
    required this.pair,
    required this.strategy,
    required this.timeframe,
    required this.params,
    required this.maxPositionUsd,
    required this.stopLossPct,
    required this.takeProfitPct,
    required this.maxDailyLossUsd,
  });

  /// ฟอร์มเปล่าสำหรับบอทตัวใหม่ — ค่าเริ่มต้นทั้งหมดมาจากแคตตาล็อก
  factory AiBotDraft.blank({
    required AiBotCatalog catalog,
    required AiBotStrategy strategy,
    required String name,
    required String pair,
  }) {
    final limits = catalog.limits;
    final tf = strategy.timeframes.isNotEmpty
        ? strategy.timeframes.first
        : (catalog.timeframes.isNotEmpty ? catalog.timeframes.first : '1h');

    return AiBotDraft(
      name: name,
      pair: pair,
      strategy: strategy.code,
      timeframe: tf,
      params: strategy.defaultParams(catalog.commonParams),
      maxPositionUsd: limits.maxPositionUsd.defaultValue,
      stopLossPct: limits.stopLossPct.defaultValue,
      takeProfitPct: limits.takeProfitPct.defaultValue,
      maxDailyLossUsd: limits.maxDailyLossUsd.defaultValue,
    );
  }

  /// ฟอร์มสำหรับแก้บอทเดิม
  ///
  /// ช่อง "ทุนสูงสุดต่อไม้" ตั้งต้นด้วยค่าที่ผู้ใช้ **ตั้งใจ** ไว้
  /// ไม่ใช่ค่าที่ถูกเพดานแพลนบีบ — ไม่งั้นทุกครั้งที่เปิดฟอร์มมาแก้
  /// ค่าจะถูกกดลงทีละขั้นจนผู้ใช้งงว่าตัวเลขที่ตั้งไว้หายไปไหน
  factory AiBotDraft.fromBot(AiBot bot) => AiBotDraft(
    name: bot.name,
    pair: bot.pair,
    strategy: bot.strategy,
    timeframe: bot.timeframe,
    params: Map<String, dynamic>.from(bot.params),
    maxPositionUsd: bot.risk.maxPositionUsdRequested,
    stopLossPct: bot.risk.stopLossPct,
    takeProfitPct: bot.risk.takeProfitPct,
    maxDailyLossUsd: bot.risk.maxDailyLossUsd,
  );

  AiBotDraft copyWith({
    String? name,
    String? pair,
    String? strategy,
    String? timeframe,
    Map<String, dynamic>? params,
    double? maxPositionUsd,
    double? stopLossPct,
    double? takeProfitPct,
    double? maxDailyLossUsd,
  }) => AiBotDraft(
    name: name ?? this.name,
    pair: pair ?? this.pair,
    strategy: strategy ?? this.strategy,
    timeframe: timeframe ?? this.timeframe,
    params: params ?? this.params,
    maxPositionUsd: maxPositionUsd ?? this.maxPositionUsd,
    stopLossPct: stopLossPct ?? this.stopLossPct,
    takeProfitPct: takeProfitPct ?? this.takeProfitPct,
    maxDailyLossUsd: maxDailyLossUsd ?? this.maxDailyLossUsd,
  );

  /// เปลี่ยนกลยุทธ์ = พารามิเตอร์ชุดเดิมใช้ไม่ได้แล้ว ต้องรีเซ็ตเป็นค่าเริ่มต้น
  /// ของกลยุทธ์ใหม่ และดันกรอบเวลาให้อยู่ในชุดที่กลยุทธ์นั้นรองรับ
  /// (ไม่งั้นผู้ใช้เลือก dca + 1m แล้วโดน 422 ตอนกดบันทึก)
  AiBotDraft withStrategy(AiBotStrategy next, AiBotCatalog catalog) {
    final tf = next.timeframes.contains(timeframe)
        ? timeframe
        : (next.timeframes.isNotEmpty ? next.timeframes.first : timeframe);
    return copyWith(
      strategy: next.code,
      timeframe: tf,
      params: next.defaultParams(catalog.commonParams),
    );
  }

  /// คู่เทรดในรูปที่เซิร์ฟเวอร์ยอมรับ (ต้องมี `/` ไม่ใช่ `-`)
  String get normalizedPair => pair.replaceAll('-', '/').toUpperCase().trim();

  bool get hasName => name.trim().isNotEmpty;

  /// กรอบความเสี่ยงในรูป payload — ผ่าน [BotRisk.payload] จุดเดียว
  /// ที่นั่นคือที่ที่กับดัก `max_position_usd_requested` ถูกปิดไว้
  Map<String, dynamic> get riskPayload => BotRisk.payload(
    maxPositionUsd: maxPositionUsd,
    stopLossPct: stopLossPct,
    takeProfitPct: takeProfitPct,
    maxDailyLossUsd: maxDailyLossUsd,
  );

  Map<String, dynamic> toRequestJson() => {
    'name': name.trim(),
    'pair': normalizedPair,
    'strategy': strategy,
    'timeframe': timeframe,
    'params': params,
    'risk': BotRisk.payload(
      maxPositionUsd: maxPositionUsd,
      stopLossPct: stopLossPct,
      takeProfitPct: takeProfitPct,
      maxDailyLossUsd: maxDailyLossUsd,
    ),
  };
}

// ══════════════════════════════════════════════════════════════════
// ชื่อพ้อง (alias) ของชนิดข้อมูล
// ══════════════════════════════════════════════════════════════════

// ไฟล์นี้ถูกเรียกใช้จากทั้งชั้นบริการ (`ai_bot_api.dart`) และชั้นหน้าจอ
// (`screens/ai_trade/`, `widgets/ai_bot/`) ซึ่งเขียนคนละรอบกัน จึงมีชื่อเรียก
// ของสิ่งเดียวกันปนกันอยู่สองแบบ — ประกาศเป็น typedef ให้ทั้งสองชื่อชี้คลาส
// เดียวกัน แทนที่จะไล่แก้ให้ตรงกันแล้วพังสลับไปมา
//
// typedef ใช้เป็นเป้าของ constructor ได้ด้วย (`AiMarketRisk.fromJson(...)`
// เรียกได้เหมือน `AiRiskView.fromJson(...)`) จึงใช้แทนกันได้ทุกที่จริงๆ

/// ด่านความเสี่ยงของคู่เทรด
typedef AiMarketRisk = AiRiskView;

/// ยอดเครดิต + เดินบัญชี
typedef AiBotCredits = CreditLedger;

/// ใบแจ้งความจำนงเติมเครดิต
typedef AiBotTopupRequest = TopupRequest;

/// ผลของการสั่งบอทเดินหนึ่งรอบ
typedef AiBotTickResult = TickOutcome;

/// คำแนะนำจากที่ปรึกษา AI
typedef AiBotAdvice = AiAdvice;
typedef AdvisorReply = AiAdvice;

/// หนึ่งหน้าของประวัติการตัดสินใจ
typedef AiBotDecisionPage = DecisionPage;

/// หนึ่งรอบความคิดของบอท
typedef BotDecision = AiBotDecision;

/// สถิติย้อนหลังทั้งชุด
typedef AnalyticsBundle = AiBotAnalytics;

/// ซองของมุมมองตลาด (enabled / shadow / reason / view)
typedef AiMarketViewData = AiMarketView;
typedef MarketView = AiMarketView;

/// เนื้อในของมุมมองตลาด
typedef MarketViewBody = AiMarketViewBody;

/// หนึ่งเหรียญในมุมมองตลาด
typedef MarketCoin = CoinView;

/// แพ็กเติมเครดิต
typedef AiBotPack = CreditPack;

// ══════════════════════════════════════════════════════════════════
// ตัวช่วยแปลงชนิด (JSON ของ PHP ส่ง int/double/string ปนกันมาตลอด)
// ══════════════════════════════════════════════════════════════════

/// ตัวเลขที่ "ต้องมีค่า" — ใช้ fallback เมื่อไม่มีข้อมูล
double _d(dynamic v, double fallback) {
  if (v is double) return v;
  if (v is int) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? fallback;
  return fallback;
}

/// ตัวเลขที่ "ไม่มีก็ได้" — คืน null เมื่อไม่มี
///
/// สำคัญมากกับตัวเลขเรื่องเงิน: `0` กับ `ไม่รู้` ต่างกันสิ้นเชิง
/// (`realized_pnl` ของไม้ซื้อ, `win_rate` ตอนยังไม่มีไม้ปิด, `max_capital_usd`)
double? _dn(dynamic v) {
  if (v == null) return null;
  if (v is double) return v;
  if (v is int) return v.toDouble();
  if (v is String) return double.tryParse(v);
  return null;
}

int _i(dynamic v, int fallback) {
  if (v is int) return v;
  if (v is double) return v.round();
  if (v is String) return int.tryParse(v) ?? fallback;
  return fallback;
}

int? _in(dynamic v) {
  if (v == null) return null;
  if (v is int) return v;
  if (v is double) return v.round();
  if (v is String) return int.tryParse(v);
  return null;
}

String _s(dynamic v, [String fallback = '']) {
  if (v is String && v.isNotEmpty) return v;
  if (v == null) return fallback;
  final s = v.toString();
  return s.isEmpty ? fallback : s;
}

String? _sn(dynamic v) {
  if (v == null) return null;
  if (v is String) return v.isEmpty ? null : v;
  final s = v.toString();
  return s.isEmpty ? null : s;
}

bool? _bn(dynamic v) {
  if (v == null) return null;
  if (v is bool) return v;
  if (v is num) return v != 0;
  if (v is String) return v == 'true' || v == '1';
  return null;
}

Map<String, dynamic>? _map(dynamic v) {
  if (v is Map<String, dynamic>) return v;
  if (v is Map) return v.map((k, val) => MapEntry(k.toString(), val));
  return null;
}

List<Map<String, dynamic>> _mapList(dynamic v) {
  if (v is! List) return const [];
  final out = <Map<String, dynamic>>[];
  for (final e in v) {
    final m = _map(e);
    if (m != null) out.add(m);
  }
  return out;
}

List<String> _strList(dynamic v) {
  if (v is! List) return const [];
  final out = <String>[];
  for (final e in v) {
    if (e == null) continue;
    final s = e is String ? e : e.toString();
    if (s.isNotEmpty) out.add(s);
  }
  return out;
}

List<num> _numList(dynamic v) {
  if (v is! List) return const [];
  final out = <num>[];
  for (final e in v) {
    if (e is num) {
      out.add(e);
    } else if (e is String) {
      final n = num.tryParse(e);
      if (n != null) out.add(n);
    }
  }
  return out;
}

/// พาดหัวข่าวของมุมมองตลาด — ปกติเป็น String แต่ analyst อาจเก็บเป็น object
List<String> _headlines(dynamic v) {
  if (v is! List) return const [];
  final out = <String>[];
  for (final e in v) {
    if (e == null) continue;
    if (e is String) {
      if (e.isNotEmpty) out.add(e);
      continue;
    }
    final m = _map(e);
    if (m != null) {
      final t = _sn(m['title']) ?? _sn(m['headline']);
      if (t != null) out.add(t);
      continue;
    }
    out.add(e.toString());
  }
  return out;
}

/// วันเวลา — รองรับทั้ง ISO8601 (มี offset) และ MySQL datetime ดิบ
DateTime? _dt(dynamic v) {
  if (v == null) return null;
  if (v is DateTime) return v;
  final s = v is String ? v : v.toString();
  if (s.isEmpty) return null;
  return DateTime.tryParse(s);
}

/// เผื่อไฟล์อื่นต้องอ่าน bool แบบทนพัง (เช่น อ่าน params ของบอท)
bool? readBool(dynamic v) => _bn(v);

/// Developed by Xman Studio
